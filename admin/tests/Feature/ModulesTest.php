<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\DiningTable;
use App\Models\Expense;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(MenuSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->staff = User::factory()->create(['role' => 'staff']);
    }

    public function test_counter_bill_for_a_table_takes_stock_and_cancel_returns_it(): void
    {
        $table = DiningTable::create(['name' => 'T1', 'seats' => 4]);
        $pizza = MenuItem::where('code', '5')->first();
        $pizza->update(['track_stock' => true, 'stock_qty' => 5]);

        $this->actingAs($this->staff)->post(route('billing.store'), [
            'lines' => [$pizza->id => 2, MenuItem::where('code', '12')->value('id') => 1],
            'order_type' => 'dine_in', 'dining_table_id' => $table->id,
            'payment_method' => 'upi', 'paid_now' => '1',
        ])->assertRedirect();

        $order = Order::first();
        $this->assertSame('pos', $order->source);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(44700 + 2235, $order->total_paise); // 2×179 + 89, 5% GST, no delivery fee
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame(3, $pizza->fresh()->stock_qty);
        $this->assertTrue($table->fresh()->openOrder->is($order));

        $this->actingAs($this->admin)->post(route('orders.status', $order), ['status' => 'cancelled', 'note' => 'Guest left']);
        $this->assertSame(5, $pizza->fresh()->stock_qty);
        $this->assertSame(0, $order->notifications()->count(), 'walk-in orders get no WhatsApp message');
        $this->assertNull($table->fresh()->openOrder);
    }

    public function test_bill_cannot_sell_more_than_stock(): void
    {
        $pizza = MenuItem::where('code', '5')->first();
        $pizza->update(['track_stock' => true, 'stock_qty' => 1]);

        $this->actingAs($this->staff)->post(route('billing.store'), [
            'lines' => [$pizza->id => 2], 'order_type' => 'takeaway', 'payment_method' => 'cash',
        ])->assertSessionHasErrors('bill');

        $this->assertSame(0, Order::count());
        $this->assertSame(1, $pizza->fresh()->stock_qty);
    }

    public function test_sold_out_item_disappears_from_whatsapp_menu(): void
    {
        config(['restaurant.internal_token' => str_repeat('k', 40)]);
        MenuItem::where('code', '5')->update(['track_stock' => true, 'stock_qty' => 0]);

        $codes = collect($this->withToken(str_repeat('k', 40))->getJson('/internal/v1/menu')->json('categories'))
            ->flatMap(fn ($c) => array_column($c['items'], 'code'));
        $this->assertNotContains('5', $codes);
    }

    public function test_inventory_adjust_and_history(): void
    {
        $item = MenuItem::where('code', '8')->first();
        $this->actingAs($this->admin)->put(route('inventory.settings', $item), ['track_stock' => '1', 'low_stock_at' => 3]);
        $this->actingAs($this->staff)->post(route('inventory.adjust', $item), ['mode' => 'add', 'quantity' => 20])->assertSessionHas('status');
        $this->actingAs($this->staff)->post(route('inventory.adjust', $item), ['mode' => 'remove', 'quantity' => 25])->assertSessionHasErrors('stock');
        $this->actingAs($this->staff)->post(route('inventory.adjust', $item), ['mode' => 'set', 'quantity' => 2]);

        $this->assertSame(2, $item->fresh()->stock_qty);
        $this->assertTrue($item->fresh()->isLowStock());
        $this->actingAs($this->staff)->get(route('inventory.history', $item))->assertOk()->assertSee('Restocked');
    }

    public function test_staff_cannot_open_admin_pages_but_can_use_daily_pages(): void
    {
        foreach (['menu.index', 'reports.index', 'expenses.index', 'staff.index', 'settings.edit'] as $r) {
            $this->actingAs($this->staff)->get(route($r))->assertForbidden();
        }
        foreach (['dashboard', 'orders.index', 'billing.index', 'billing.create', 'tables.index', 'kitchen', 'inventory.index', 'customers.index'] as $r) {
            $this->actingAs($this->staff)->get(route($r))->assertOk();
        }
        $this->actingAs($this->staff)->get('/')->assertDontSee('Expenses');
    }

    public function test_admin_pages_render(): void
    {
        DiningTable::create(['name' => 'T1', 'seats' => 2]);
        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'Dairy', 'description' => 'Paneer', 'amount_paise' => 45000]);
        foreach (['menu.index', 'reports.index', 'expenses.index', 'staff.index', 'settings.edit', 'tables.index', 'billing.create'] as $r) {
            $this->actingAs($this->admin)->get(route($r))->assertOk();
        }
        $html = $this->actingAs($this->admin)->get(route('reports.index'))->getContent(); file_put_contents('/tmp/rep.html', $html); $this->assertStringContainsString('₹450', $html);
    }

    public function test_report_csv_export(): void
    {
        $order = app(OrderService::class)->placeCounterOrder([MenuItem::where('code', '1')->value('id') => 1], \App\Enums\OrderType::Takeaway, null, 'Ravi', null, \App\Enums\PaymentMethod::Cash, true, null, $this->admin);

        $csv = $this->actingAs($this->admin)->get(route('reports.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString($order->order_number, $csv);
        $this->assertStringContainsString('Ravi', $csv);
        $this->actingAs($this->admin)->get(route('billing.print', $order))->assertOk()->assertSee('TOTAL');
    }

    public function test_expense_crud(): void
    {
        $this->actingAs($this->admin)->post(route('expenses.store'), [
            'spent_on' => now()->toDateString(), 'category' => 'Gas & Fuel', 'description' => 'Cylinder', 'amount' => '1100.50', 'payment_method' => 'cash',
        ])->assertSessionHas('status');
        $e = Expense::first();
        $this->assertSame(110050, $e->amount_paise);

        $this->actingAs($this->admin)->delete(route('expenses.destroy', $e));
        $this->assertSame(0, Expense::count());
    }

    public function test_staff_management_rules(): void
    {
        $this->actingAs($this->admin)->post(route('staff.store'), ['name' => 'Ramesh', 'email' => 'ramesh@cafe.test', 'role' => 'staff', 'password' => 'secret123'])
            ->assertSessionHas('status');
        $ramesh = User::where('email', 'ramesh@cafe.test')->first();
        $this->assertFalse($ramesh->isAdmin());

        // can't remove own admin access
        $this->actingAs($this->admin)->put(route('staff.update', $this->admin), ['name' => 'A', 'role' => 'staff', 'is_active' => '1'])
            ->assertSessionHasErrors('staff');

        // deactivated staff can't log in
        $this->actingAs($this->admin)->put(route('staff.update', $ramesh), ['name' => 'Ramesh', 'role' => 'staff']);
        $this->assertFalse($ramesh->fresh()->is_active);
        auth()->logout();
        $this->post('/login', ['email' => 'ramesh@cafe.test', 'password' => 'secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
