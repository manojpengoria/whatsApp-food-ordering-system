<?php

namespace App\Jobs;

use App\Models\MenuItem;
use App\Services\CatalogSync;
use App\Services\CatalogSyncException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Pushes one item's latest availability to the WhatsApp catalog (e.g. when it sells out). */
class SyncCatalogItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $menuItemId) {}

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(CatalogSync $catalog): void
    {
        $item = MenuItem::query()->with('category')->find($this->menuItemId);
        if (! $item || ! $item->catalog_synced_at || ! $item->image_path || $catalog->problems()) {
            return;
        }
        try {
            $catalog->syncItems([$item]);
        } catch (CatalogSyncException $e) {
            Log::warning('Catalog availability update failed', ['item' => $item->retailer_id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
