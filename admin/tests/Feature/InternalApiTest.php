<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Settings;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';
    private const WA = '919876543210';

    protected function setUp(): void
    {
        parent::setUp();
        config(['restaurant.internal_token' => self::TOKEN]);
        $this->seed(MenuSeeder::class);
        // Open 24 hours so tests don't depend on the clock.
        app(Settings::class)->save(['opening_time' => '00:00', 'closing_time' => '00:00']);
    }

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->withToken(self::TOKEN)->json($method, '/internal/v1/'.$uri, $data);
    }

    public function test_rejects_missing_or_wrong_token(): void
    {
        $this->getJson('/internal/v1/menu')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/internal/v1/menu')->assertUnauthorized();
    }

    public function test_menu_lists_only_available_items(): void
    {
        \App\Models\MenuItem::where('code', '13')->update(['is_available' => false]);

        $codes = collect($this->api('GET', 'menu')->assertOk()->json('categories'))->flatMap(fn ($c) => array_column($c['items'], 'code'));

        $this->assertContains('5', $codes);
        $this->assertNotContains('13', $codes);
    }

    public function test_cart_totals_include_gst_and_delivery_fee(): void
    {
        $this->api('POST', 'cart/items', ['wa_id' => self::WA, 'code' => '5', 'quantity' => 2])->assertOk();
        $this->api('POST', 'cart/items', ['wa_id' => self::WA, 'code' => '12', 'quantity' => 1])->assertOk();

        $cart = $this->api('GET', 'cart?wa_id='.self::WA.'&order_type=delivery')->assertOk()->json('cart');

        $this->assertSame(44700, $cart['subtotal_paise']);   // 2×179 + 89
        $this->assertSame(2235, $cart['tax_paise']);         // 5%
        $this->assertSame(3000, $cart['delivery_fee_paise']);
        $this->assertSame(49935, $cart['total_paise']);
    }

    public function test_unknown_item_and_bad_phone_are_rejected(): void
    {
        $this->api('POST', 'cart/items', ['wa_id' => self::WA, 'code' => '999', 'quantity' => 1])
            ->assertStatus(422)->assertJson(['error' => 'item_not_found']);
        $this->api('POST', 'cart/items', ['wa_id' => 'abc', 'code' => '5', 'quantity' => 1])
            ->assertStatus(422)->assertJson(['error' => 'validation_failed']);
    }

    public function test_placing_an_order_is_idempotent_and_empties_the_cart(): void
    {
        $this->api('POST', 'cart/items', ['wa_id' => self::WA, 'code' => '5', 'quantity' => 1]);
        $payload = ['wa_id' => self::WA, 'order_type' => 'takeaway', 'idempotency_key' => 'wa:wamid.ABC'];

        $first = $this->api('POST', 'orders', $payload)->assertCreated()->json('order.number');
        $second = $this->api('POST', 'orders', $payload)->assertOk()->json('order.number');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::count());
        $this->assertTrue($this->api('GET', 'cart?wa_id='.self::WA)->json('cart.is_empty'));
    }

    public function test_delivery_needs_an_address_and_closed_restaurant_refuses(): void
    {
        $this->api('POST', 'cart/items', ['wa_id' => self::WA, 'code' => '5', 'quantity' => 1]);

        $this->api('POST', 'orders', ['wa_id' => self::WA, 'order_type' => 'delivery', 'delivery_address' => 'short', 'idempotency_key' => 'k1'])
            ->assertStatus(422)->assertJson(['error' => 'address_required']);

        app(Settings::class)->save(['accepting_orders' => '0']);
        $this->api('POST', 'orders', ['wa_id' => self::WA, 'order_type' => 'takeaway', 'idempotency_key' => 'k2'])
            ->assertStatus(409)->assertJson(['error' => 'restaurant_closed']);
    }

    public function test_order_keeps_price_snapshot_after_menu_change(): void
    {
        $this->api('POST', 'cart/items', ['wa_id' => self::WA, 'code' => '5', 'quantity' => 1]);
        $this->api('POST', 'orders', ['wa_id' => self::WA, 'order_type' => 'takeaway', 'idempotency_key' => 'k3'])->assertCreated();

        \App\Models\MenuItem::where('code', '5')->update(['price_paise' => 99900, 'name' => 'Renamed']);

        $item = Order::first()->items()->first();
        $this->assertSame(17900, $item->unit_price_paise);
        $this->assertSame('Margherita Pizza', $item->item_name);
    }
}
