<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\ExpireUnpaidOrder;
use App\Jobs\SyncCatalogItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Settings;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentsAndLiveTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';
    private const WA = '919876543210';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'restaurant.internal_token' => self::TOKEN,
            'restaurant.catalog.id' => '2078597249765769',
            'services.razorpay.base_url' => 'https://rzp.test/v1',
            'services.bot.url' => 'http://bot.test', 'services.bot.token' => self::TOKEN,
        ]);
        $this->seed(MenuSeeder::class);
        $s = app(Settings::class);
        $s->save(['opening_time' => '00:00', 'closing_time' => '00:00',
            'pay_whatsapp_enabled' => '1', 'wa_pay_gateway' => 'razorpay', 'wa_pay_config_name' => 'cafe-rzp',
            'pay_razorpay_enabled' => '1', 'razorpay_key_id' => 'rzp_test_KEY', 'payment_window_minutes' => '15']);
        $s->saveSecret('razorpay_key_secret', 'secret');
        $s->saveSecret('razorpay_webhook_secret', 'whsec');
    }

    private function api(string $m, string $u, array $d = [])
    {
        return $this->withToken(self::TOKEN)->json($m, '/internal/v1/'.$u, $d);
    }

    private function placeOrder(string $method, string $type = 'takeaway')
    {
        $this->api('POST', 'cart/items', ['wa_id' => self::WA, 'code' => '5', 'quantity' => 1]);

        return $this->api('POST', 'orders', array_filter(['wa_id' => self::WA, 'order_type' => $type, 'idempotency_key' => uniqid(), 'payment_method' => $method,
            'delivery_address' => $type === 'delivery' ? 'B-12 Vaishali Nagar, Jaipur 302021' : null]));
    }

    public function test_payment_methods_and_encrypted_secret(): void
    {
        $this->assertSame(['whatsapp_pay', 'razorpay', 'cash'], $this->api('GET', 'restaurant')->json('payment_methods'));
        $this->assertNotSame('secret', \App\Models\Setting::find('razorpay_key_secret')->value);
    }

    public function test_whatsapp_pay_order_is_hidden_and_returns_valid_order_details(): void
    {
        Queue::fake();
        MenuItem::where('code', '5')->update(['catalog_synced_at' => now()]);
        $res = $this->placeOrder('whatsapp_pay', 'delivery')->assertCreated();

        $order = Order::first();
        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);
        $this->assertNull($order->visible_at);
        Queue::assertPushed(ExpireUnpaidOrder::class);

        $p = $res->json('order.payment.order_details');
        $this->assertSame($order->order_number.'-1', $p['reference_id']);
        $this->assertSame('cafe-rzp', $p['payment_settings'][0]['payment_gateway']['configuration_name']);
        $this->assertSame('2078597249765769', $p['order']['catalog_id']);
        // Meta rule: total = subtotal + tax + shipping
        $this->assertSame($p['total_amount']['value'], $p['order']['subtotal']['value'] + $p['order']['tax']['value'] + $p['order']['shipping']['value']);
        $this->assertSame('302021', $p['beneficiaries'][0]['postal_code']);
        $this->assertSame('wm_'.MenuItem::where('code', '5')->value('id'), $p['order']['items'][0]['retailer_id']);

        // not on any staff screen
        $admin = User::factory()->create();
        $this->actingAs($admin)->get(route('orders.index'))->assertDontSee($order->order_number);
        $this->actingAs($admin)->get(route('kitchen'))->assertDontSee($order->order_number);
        $this->actingAs($admin)->get(route('orders.index', ['status' => 'awaiting_payment']))->assertSee($order->order_number);
        $this->assertCount(0, $this->actingAs($admin)->getJson(route('live.poll', ['since' => now()->subMinute()->timestamp]))->json('new_orders'));
    }

    public function test_captured_payment_makes_order_visible_with_alert_once(): void
    {
        Queue::fake();
        $this->placeOrder('whatsapp_pay');
        $order = Order::first();
        $since = now()->subSecond()->timestamp;

        $this->api('POST', 'payments/whatsapp', ['reference_id' => $order->payment_reference, 'status' => 'pending', 'amount_value' => 0])->assertOk();
        $this->assertSame(OrderStatus::AwaitingPayment, $order->fresh()->status);

        $this->api('POST', 'payments/whatsapp', ['reference_id' => $order->payment_reference, 'status' => 'captured', 'amount_value' => 100, 'pg_transaction_id' => 'pay_X'])
            ->assertStatus(409); // amount too low

        $body = ['reference_id' => $order->payment_reference, 'status' => 'captured', 'amount_value' => $order->total_paise, 'pg_transaction_id' => 'pay_X'];
        $this->api('POST', 'payments/whatsapp', $body)->assertOk()->assertJson(['status' => 'pending']);
        $this->api('POST', 'payments/whatsapp', $body)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertNotNull($order->visible_at);
        $this->assertSame(1, $order->notifications()->where('event', 'order.payment_received')->count());

        $poll = $this->actingAs(User::factory()->create())->getJson(route('live.poll', ['since' => $since]))->json();
        $this->assertSame($order->order_number, $poll['new_orders'][0]['number']);
    }

    public function test_unpaid_order_expires_after_lookup_and_returns_stock(): void
    {
        Queue::fake();
        $pizza = MenuItem::where('code', '5')->first();
        $pizza->update(['track_stock' => true, 'stock_qty' => 3]);
        $this->placeOrder('whatsapp_pay');
        $order = Order::first();
        $this->assertSame(2, $pizza->fresh()->stock_qty);

        Http::fake(['bot.test/*' => Http::response(['status' => 'pending', 'amount_value' => 0])]);
        (new ExpireUnpaidOrder($order->id))->handle(app(OrderService::class));

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(3, $pizza->fresh()->stock_qty);
        $this->assertSame(1, $order->notifications()->where('event', 'order.payment_expired')->count());
    }

    public function test_expiry_finds_a_missed_payment_and_keeps_the_order(): void
    {
        Queue::fake();
        $this->placeOrder('whatsapp_pay');
        $order = Order::first();

        Http::fake(['bot.test/*' => Http::response(['status' => 'captured', 'amount_value' => $order->total_paise, 'pg_transaction_id' => 'pay_late'])]);
        (new ExpireUnpaidOrder($order->id))->handle(app(OrderService::class));

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame('pay_late', $order->fresh()->payment_ref);
    }

    public function test_razorpay_link_order_is_hidden_until_webhook(): void
    {
        Queue::fake();
        Http::fake(['rzp.test/*' => Http::response(['id' => 'plink_9', 'short_url' => 'https://rzp.io/i/x'])]);
        $res = $this->placeOrder('razorpay')->assertCreated();
        $this->assertSame('https://rzp.io/i/x', $res->json('order.payment.link_url'));
        $order = Order::first();
        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);

        $raw = json_encode(['event' => 'payment_link.paid', 'payload' => [
            'payment_link' => ['entity' => ['id' => 'plink_9', 'reference_id' => $order->order_number, 'amount_paid' => $order->total_paise]],
            'payment' => ['entity' => ['id' => 'pay_77']],
        ]]);
        $this->api('POST', 'payments/razorpay', ['raw' => $raw, 'signature' => hash_hmac('sha256', $raw, 'wrong')])->assertStatus(401);
        $this->api('POST', 'payments/razorpay', ['raw' => $raw, 'signature' => hash_hmac('sha256', $raw, 'whsec')])->assertOk();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame('pay_77', $order->fresh()->payment_ref);
    }

    public function test_cash_order_is_visible_at_once(): void
    {
        $this->placeOrder('cash')->assertCreated();
        $this->assertSame(OrderStatus::Pending, Order::first()->status);
        $this->assertNotNull(Order::first()->visible_at);
    }

    public function test_catalog_cart_is_trimmed_to_stock_and_sellout_updates_catalog(): void
    {
        Queue::fake();
        $burger = MenuItem::where('code', '1')->first();
        $burger->update(['track_stock' => true, 'stock_qty' => 2, 'catalog_synced_at' => now()]);

        $res = $this->api('PUT', 'cart', ['wa_id' => self::WA, 'items' => [['retailer_id' => 'wm_'.$burger->id, 'quantity' => 5]]])->assertOk();
        $this->assertSame([['name' => 'Veg Burger', 'wanted' => 5, 'given' => 2]], $res->json('reduced'));

        $this->api('POST', 'orders', ['wa_id' => self::WA, 'order_type' => 'takeaway', 'idempotency_key' => 'k', 'payment_method' => 'cash'])->assertCreated();
        $this->assertSame(0, $burger->fresh()->stock_qty);
        Queue::assertPushed(SyncCatalogItem::class, fn ($j) => $j->menuItemId === $burger->id);
    }
}
