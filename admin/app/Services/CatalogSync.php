<?php

namespace App\Services;

use App\Models\MenuItem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Pushes the admin menu into the WhatsApp (Meta Commerce) catalog with the items_batch API.
 * It only creates/updates items it owns (retailer ids "wm_<id>") and never deletes
 * products that were made by hand in Commerce Manager.
 */
class CatalogSync
{
    public function __construct(private readonly Settings $settings) {}

    /** What is missing before a sync can run (empty = ready). */
    public function problems(): array
    {
        $c = config('restaurant.catalog');
        $problems = [];
        if (blank($c['id'])) {
            $problems[] = 'META_CATALOG_ID is not set in admin/.env';
        }
        if (blank($c['token'])) {
            $problems[] = 'META_CATALOG_TOKEN is not set in admin/.env';
        }
        $media = (string) config('restaurant.public_media_url');
        $host = (string) parse_url($media, PHP_URL_HOST);
        $placeholder = preg_match('/your-tunnel|aapka-tunnel|example\./i', $media) === 1;
        if (! str_starts_with($media, 'https://') || ! str_contains($host, '.') || $placeholder || ! str_ends_with(rtrim($media, '/'), '/media/menu')) {
            $problems[] = 'PUBLIC_MEDIA_URL must be your real tunnel address ending in /media/menu, e.g. https://abcd.ngrok-free.app/media/menu';
        }

        return $problems;
    }

    /**
     * @return array{sent: int, skipped: list<string>, handles: list<string>}
     */
    public function syncAll(): array
    {
        if ($p = $this->problems()) {
            throw new CatalogSyncException(implode('. ', $p).'.');
        }

        $items = MenuItem::query()->with('category')->orderBy('id')->get();
        $requests = [];
        $skipped = [];
        $sentIds = [];

        foreach ($items as $item) {
            if (! $item->image_path) {
                $skipped[] = $item->name;
                continue;
            }
            $requests[] = ['method' => 'UPDATE', 'data' => $this->productData($item)];
            $sentIds[] = $item->id;
        }

        if ($requests === []) {
            throw new CatalogSyncException('No items have a photo yet. WhatsApp catalog needs a photo for every product.');
        }

        $handles = [];
        foreach (array_chunk($requests, 1000) as $chunk) {
            $handles = array_merge($handles, $this->postBatch($chunk));
        }

        MenuItem::query()->whereIn('id', $sentIds)->update(['catalog_synced_at' => now()]);

        $this->settings->save([
            'catalog_last_sync_at' => now()->toIso8601String(),
            'catalog_last_handles' => json_encode($handles),
            'catalog_last_summary' => count($requests).' items sent'.($skipped ? ', '.count($skipped).' skipped (no photo)' : ''),
        ]);

        return ['sent' => count($requests), 'skipped' => $skipped, 'handles' => $handles];
    }

    /** Update just these items (used when stock runs out / comes back). */
    public function syncItems(iterable $items): void
    {
        $requests = [];
        foreach ($items as $item) {
            if ($item->image_path) {
                $requests[] = ['method' => 'UPDATE', 'data' => $this->productData($item)];
            }
        }
        if ($requests) {
            $this->postBatch($requests);
        }
    }

    /** Removes one of OUR items from the catalog (called when an item is deleted in admin). */
    public function deleteItem(string $retailerId): void
    {
        if ($this->problems()) {
            return;
        }
        $this->postBatch([['method' => 'DELETE', 'data' => ['id' => $retailerId]]]);
    }

    /**
     * Asks Meta how the last batch went (it processes batches in the background).
     *
     * @return list<array{status: string, errors: list<string>}>
     */
    public function lastBatchStatus(): array
    {
        $handles = json_decode((string) $this->settings->get('catalog_last_handles', '[]'), true) ?: [];
        $out = [];
        foreach ($handles as $handle) {
            $res = $this->graph()->get($this->url('check_batch_request_status'), ['handle' => $handle]);
            if (! $res->successful()) {
                $out[] = ['status' => 'error', 'errors' => [$this->errorMessage($res)]];
                continue;
            }
            $row = $res->json('data.0') ?? [];
            $errors = [];
            foreach ($row['errors'] ?? [] as $e) {
                $errors[] = trim(($e['id'] ?? '').' '.($e['message'] ?? ''));
            }
            foreach ($row['warnings'] ?? [] as $w) {
                $errors[] = 'Warning: '.trim(($w['id'] ?? '').' '.($w['message'] ?? ''));
            }
            $out[] = ['status' => (string) ($row['status'] ?? 'unknown'), 'errors' => $errors];
        }

        return $out;
    }

    public function productData(MenuItem $item): array
    {
        $available = $item->isOrderable();
        $restaurant = $this->settings->get('restaurant_name');

        return [
            'id' => $item->retailer_id,
            'title' => mb_substr($item->name, 0, 150),
            'description' => mb_substr($item->description ?: $item->name, 0, 5000),
            'availability' => $available ? 'in stock' : 'out of stock',
            'condition' => 'new',
            'price' => number_format($item->price_paise / 100, 2, '.', '').' INR',
            'link' => $item->public_image_url,
            'image_link' => $item->public_image_url,
            'brand' => $restaurant,
            'product_type' => $item->category?->name,
        ];
    }

    private function postBatch(array $requests): array
    {
        try {
            $res = $this->graph()->asForm()->post($this->url('items_batch'), [
                'item_type' => 'PRODUCT_ITEM',
                'allow_upsert' => 'true',
                'requests' => json_encode($requests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (ConnectionException $e) {
            throw new CatalogSyncException('Could not reach Meta: '.$e->getMessage());
        }

        if (! $res->successful()) {
            throw new CatalogSyncException($this->errorMessage($res));
        }

        return $res->json('handles') ?? [];
    }

    private function graph()
    {
        return Http::withToken((string) config('restaurant.catalog.token'))->acceptJson()->connectTimeout(5)->timeout(30);
    }

    private function url(string $edge): string
    {
        $c = config('restaurant.catalog');

        return rtrim($c['graph_base_url'], '/').'/'.$c['graph_version'].'/'.$c['id'].'/'.$edge;
    }

    private function errorMessage($res): string
    {
        $e = $res->json('error') ?? [];
        $msg = 'Meta '.$res->status().': '.($e['message'] ?? 'unknown error');
        if (($e['code'] ?? null) === 200 || ($e['code'] ?? null) === 10) {
            $msg .= ' — the token needs the catalog_management permission for this catalog.';
        }
        if (($e['code'] ?? null) === 190) {
            $msg .= ' — the token has expired or is wrong.';
        }

        return $msg;
    }
}
