<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\User;
use App\Services\Settings;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->seed(MenuSeeder::class);
        config([
            'restaurant.internal_token' => self::TOKEN,
            'restaurant.public_media_url' => 'https://tunnel.test/media/menu',
            'restaurant.catalog.id' => '12345',
            'restaurant.catalog.token' => 'catalog-token',
            'restaurant.catalog.graph_base_url' => 'https://graph.test',
            'restaurant.catalog.graph_version' => 'v26.0',
        ]);
        $this->actingAs(User::factory()->create());
    }

    private function photo(string $name = 'menu-photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/Fixtures/'.$name)));
    }

    private function itemForm(MenuItem $item, array $extra = []): array
    {
        return array_merge([
            'menu_category_id' => $item->menu_category_id, 'code' => $item->code, 'name' => $item->name,
            'price' => '99', 'is_available' => '1', 'is_veg' => '1',
        ], $extra);
    }

    public function test_photo_upload_is_stored_and_old_photo_removed_on_replace(): void
    {
        $item = MenuItem::where('code', '1')->first();

        $this->put(route('menu.items.update', $item), $this->itemForm($item, ['image' => $this->photo()]))->assertRedirect(route('menu.index'));
        $first = $item->fresh()->image_path;
        Storage::disk('public')->assertExists($first);

        $this->put(route('menu.items.update', $item), $this->itemForm($item, ['image' => $this->photo()]));
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($item->fresh()->image_path);
    }

    public function test_small_photo_is_rejected(): void
    {
        $item = MenuItem::where('code', '1')->first();

        $this->put(route('menu.items.update', $item), $this->itemForm($item, ['image' => $this->photo('small-photo.png')]))
            ->assertSessionHasErrors('image');
        $this->assertNull($item->fresh()->image_path);
    }

    public function test_sync_sends_only_items_with_photos_in_meta_format(): void
    {
        Http::fake(['graph.test/*' => Http::response(['handles' => ['h1']])]);
        $item = MenuItem::where('code', '5')->first();
        $item->update(['image_path' => 'menu/abcdefgh12345678.jpg']);
        MenuItem::where('code', '12')->update(['image_path' => 'menu/zzzzzzzz12345678.jpg', 'is_available' => false]);

        $this->post(route('menu.catalog.sync'))->assertSessionHas('status');

        Http::assertSent(function ($request) use ($item) {
            $reqs = json_decode($request['requests'], true);
            $pizza = collect($reqs)->firstWhere('data.id', 'wm_'.$item->id)['data'];

            return str_ends_with($request->url(), '/v26.0/12345/items_batch')
                && $request['item_type'] === 'PRODUCT_ITEM'
                && count($reqs) === 2
                && $pizza['price'] === '179.00 INR'
                && $pizza['availability'] === 'in stock'
                && $pizza['image_link'] === 'https://tunnel.test/media/menu/abcdefgh12345678.jpg'
                && collect($reqs)->firstWhere('data.title', 'Cold Coffee')['data']['availability'] === 'out of stock';
        });
        $this->assertNotNull($item->fresh()->catalog_synced_at);
    }

    public function test_sync_refuses_without_configuration(): void
    {
        config(['restaurant.catalog.id' => null]);
        Http::fake();

        $this->post(route('menu.catalog.sync'))->assertSessionHasErrors('catalog');
        Http::assertNothingSent();
    }

    public function test_catalog_cart_replaces_cart_and_reports_unknown_items(): void
    {
        $pizza = MenuItem::where('code', '5')->first();
        $api = fn ($m, $u, $d = []) => $this->withToken(self::TOKEN)->json($m, '/internal/v1/'.$u, $d);

        $api('POST', 'cart/items', ['wa_id' => '919876543210', 'code' => '8', 'quantity' => 3]);

        $res = $api('PUT', 'cart', ['wa_id' => '919876543210', 'items' => [
            ['retailer_id' => 'wm_'.$pizza->id, 'quantity' => 2],
            ['retailer_id' => '12', 'quantity' => 1],     // product made by hand in Commerce Manager, id = code
            ['retailer_id' => 'wm_99999', 'quantity' => 1],
        ]])->assertOk();

        $codes = collect($res->json('cart.items'))->pluck('quantity', 'code')->all();
        $this->assertSame(['5' => 2, '12' => 1], $codes);   // fries (8) gone: catalog cart replaces
        $this->assertSame(['wm_99999'], $res->json('rejected'));
    }

    public function test_restaurant_endpoint_reports_catalog_mode(): void
    {
        $api = fn () => $this->withToken(self::TOKEN)->getJson('/internal/v1/restaurant');
        $this->assertFalse($api()->json('catalog_enabled'));

        app(Settings::class)->save(['catalog_enabled' => '1']);
        $this->assertFalse($api()->json('catalog_enabled'), 'needs at least one synced item');

        MenuItem::where('code', '1')->update(['catalog_synced_at' => now()]);
        $this->assertTrue($api()->json('catalog_enabled'));
    }
}
