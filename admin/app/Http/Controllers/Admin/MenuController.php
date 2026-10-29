<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\OrderItem;
use App\Services\CatalogSync;
use App\Services\CatalogSyncException;
use App\Services\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MenuController extends Controller
{
    public function index(Request $request, CatalogSync $catalog, Settings $settings): View
    {
        $categories = MenuCategory::query()->ordered()->with('items')->get();

        return view('admin.menu.index', [
            'catalogProblems' => $catalog->problems(),
            'catalogLastSync' => $settings->get('catalog_last_sync_at'),
            'catalogSummary' => $settings->get('catalog_last_summary'),
            'catalogEnabled' => $settings->bool('catalog_enabled'),
            'missingPhotos' => $categories->sum(fn ($c) => $c->items->whereNull('image_path')->count()),
            'categories' => $categories,
            'itemCount' => $categories->sum(fn ($c) => $c->items->count()),
            'unavailableCount' => $categories->sum(fn ($c) => $c->items->where('is_available', false)->count()),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:menu_categories,name'],
        ]);
        MenuCategory::query()->create([
            'name' => $data['name'],
            'sort_order' => (int) MenuCategory::query()->max('sort_order') + 1,
        ]);

        return back()->with('status', "Category “{$data['name']}” added.");
    }

    public function updateCategory(Request $request, MenuCategory $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('menu_categories', 'name')->ignore($category->id)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $category->update([
            'name' => $data['name'],
            'sort_order' => $data['sort_order'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', "Category “{$category->name}” saved.");
    }

    public function destroyCategory(MenuCategory $category): RedirectResponse
    {
        if ($category->items()->exists()) {
            return back()->withErrors(['category' => "Move or delete the items in “{$category->name}” first."]);
        }
        $category->delete();

        return back()->with('status', 'Category deleted.');
    }

    public function createItem(Request $request): View
    {
        $numericCodes = MenuItem::query()->pluck('code')->filter(fn ($c) => ctype_digit($c))->map(fn ($c) => (int) $c);
        $nextCode = (string) (($numericCodes->max() ?? 0) + 1);

        return view('admin.menu.item-form', [
            'item' => new MenuItem([
                'code' => $nextCode,
                'is_veg' => true,
                'is_available' => true,
                'menu_category_id' => $request->integer('category') ?: null,
            ]),
            'categories' => MenuCategory::query()->ordered()->get(),
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $data = $this->validatedItem($request);
        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($request->file('image'));
        }
        $item = MenuItem::query()->create($data);

        return redirect()->route('menu.index')->with('status', "“{$item->name}” added to the menu.");
    }

    public function editItem(MenuItem $item): View
    {
        return view('admin.menu.item-form', [
            'item' => $item,
            'categories' => MenuCategory::query()->ordered()->get(),
        ]);
    }

    public function updateItem(Request $request, MenuItem $item): RedirectResponse
    {
        $data = $this->validatedItem($request, $item);
        $old = $item->image_path;
        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($request->file('image'));
        } elseif ($request->boolean('remove_image')) {
            $data['image_path'] = null;
        }
        $item->update($data);
        if ($old && $old !== $item->image_path) {
            Storage::disk('public')->delete($old);
        }

        return redirect()->route('menu.index')->with('status', "“{$item->name}” saved.");
    }

    public function toggleItem(MenuItem $item): RedirectResponse
    {
        $item->update(['is_available' => ! $item->is_available]);
        if ($item->catalog_synced_at) {
            \App\Jobs\SyncCatalogItem::dispatch($item->id); // show/hide in the WhatsApp catalog right away
        }

        return back()->with('status', $item->is_available
            ? "“{$item->name}” is back on the WhatsApp menu."
            : "“{$item->name}” is hidden from the WhatsApp menu.");
    }

    public function destroyItem(MenuItem $item): RedirectResponse
    {
        // Past orders keep their own copy of the name and price, so deleting is safe.
        $usedInOrders = OrderItem::query()->where('menu_item_id', $item->id)->exists();
        $wasInCatalog = $item->catalog_synced_at !== null;
        $retailerId = $item->retailer_id;
        $image = $item->image_path;
        $item->delete();
        if ($image) {
            Storage::disk('public')->delete($image);
        }
        if ($wasInCatalog) {
            try {
                app(CatalogSync::class)->deleteItem($retailerId);
            } catch (CatalogSyncException $e) {
                Log::warning('Catalog delete failed', ['item' => $retailerId, 'error' => $e->getMessage()]);
            }
        }

        return redirect()->route('menu.index')->with('status', "“{$item->name}” deleted."
            .($usedInOrders ? ' Past orders still show it.' : ''));
    }

    public function syncCatalog(CatalogSync $catalog): RedirectResponse
    {
        try {
            $result = $catalog->syncAll();
        } catch (CatalogSyncException $e) {
            return back()->withErrors(['catalog' => 'Catalog sync failed: '.$e->getMessage()]);
        }

        $msg = "{$result['sent']} items sent to the WhatsApp catalog. Meta takes a few minutes to show changes.";
        if ($result['skipped']) {
            $names = array_slice($result['skipped'], 0, 4);
            $more = count($result['skipped']) - count($names);
            $msg .= ' Skipped because they have no photo: '.implode(', ', $names).($more > 0 ? " and {$more} more" : '').'.';
        }

        return back()->with('status', $msg);
    }

    public function catalogStatus(CatalogSync $catalog): RedirectResponse
    {
        try {
            $batches = $catalog->lastBatchStatus();
        } catch (\Throwable $e) {
            return back()->withErrors(['catalog' => 'Could not check: '.$e->getMessage()]);
        }
        if ($batches === []) {
            return back()->with('status', 'No sync has been run yet.');
        }
        $errors = collect($batches)->flatMap(fn ($b) => $b['errors'])->take(10)->all();
        $status = collect($batches)->pluck('status')->unique()->implode(', ');

        return $errors
            ? back()->withErrors(['catalog' => "Last sync status: {$status}. Meta reported: ".implode(' | ', $errors)])
            : back()->with('status', "Last sync status: {$status}. No errors from Meta.");
    }

    public function toggleCatalogMode(Settings $settings): RedirectResponse
    {
        $on = ! $settings->bool('catalog_enabled');
        $settings->save(['catalog_enabled' => $on ? '1' : '0']);

        return back()->with('status', $on
            ? 'WhatsApp menu now opens the photo catalog. Customers can still reply LIST for the text menu.'
            : 'WhatsApp menu is back to the text list.');
    }

    private function storeImage(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension()) === 'png' ? 'png' : 'jpg';

        return $file->storeAs('menu', Str::random(24).'.'.$ext, 'public');
    }

    private function validatedItem(Request $request, ?MenuItem $item = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'menu_category_id' => ['required', 'exists:menu_categories,id'],
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/', Rule::unique('menu_items', 'code')->ignore($item?->id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'string', 'max:12'],
            'is_veg' => ['nullable', 'boolean'],
            'is_available' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            // WhatsApp catalog needs at least 500×500 px. JPG/PNG only (Meta doesn't accept every format).
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120', 'dimensions:min_width=500,min_height=500'],
            'remove_image' => ['nullable', 'boolean'],
        ], [
            'image.dimensions' => 'The photo must be at least 500 × 500 pixels (WhatsApp catalog rule).',
            'image.mimes' => 'Upload a JPG or PNG photo.',
            'image.max' => 'The photo must be smaller than 5 MB.',
            'code.regex' => 'Use only letters and numbers (customers type this code on WhatsApp).',
        ]);

        $price = Money::parseRupees($data['price']);
        if ($price === null || $price < 100) {
            throw ValidationException::withMessages(['price' => 'Enter a price in rupees, like 179 or 179.50 (minimum ₹1).']);
        }

        return [
            'menu_category_id' => $data['menu_category_id'],
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_paise' => $price,
            'is_veg' => $request->boolean('is_veg'),
            'is_available' => $request->boolean('is_available'),
            'sort_order' => $data['sort_order'] ?? 0,
        ];
    }
}
