<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Simple per-item stock. Only items with track_stock = true are counted.
 * Call reserveForOrder()/releaseForOrder() INSIDE the order's DB transaction.
 */
class StockService
{
    /**
     * @param  array<int, int>  $quantities  menu_item_id => quantity
     */
    public function reserveForOrder(Order $order, array $quantities, ?User $by = null): void
    {
        if ($quantities === []) {
            return;
        }
        $items = MenuItem::query()->whereIn('id', array_keys($quantities))->where('track_stock', true)
            ->lockForUpdate()->get()->keyBy('id');

        foreach ($items as $id => $item) {
            $qty = $quantities[$id];
            if ($item->stock_qty < $qty) {
                throw new CartException(
                    'out_of_stock',
                    $item->stock_qty > 0
                        ? "Only {$item->stock_qty} × {$item->name} left. Please reduce the quantity."
                        : "{$item->name} is sold out.",
                    409,
                );
            }
        }
        foreach ($items as $id => $item) {
            $this->move($item, -$quantities[$id], 'sale', $order, $by);
        }
    }

    /** Puts stock back when an order is cancelled (only what this order actually took). */
    public function releaseForOrder(Order $order, ?User $by = null): void
    {
        $taken = StockMovement::query()->where('order_id', $order->id)
            ->selectRaw('menu_item_id, SUM(`change`) as total')->groupBy('menu_item_id')->pluck('total', 'menu_item_id');

        foreach ($taken as $menuItemId => $total) {
            if ((int) $total >= 0) {
                continue; // already released
            }
            $item = MenuItem::query()->lockForUpdate()->find($menuItemId);
            if ($item) {
                $this->move($item, -(int) $total, 'cancel', $order, $by);
            }
        }
    }

    /** Admin restock (+) or correction (set to an exact count). */
    public function adjust(MenuItem $item, string $mode, int $value, ?User $by, ?string $note = null): MenuItem
    {
        return DB::transaction(function () use ($item, $mode, $value, $by, $note) {
            $locked = MenuItem::query()->lockForUpdate()->findOrFail($item->id);
            $change = match ($mode) {
                'add' => $value,
                'remove' => -$value,
                'set' => $value - $locked->stock_qty,
            };
            if ($locked->stock_qty + $change < 0) {
                throw new CartException('negative_stock', 'Stock cannot go below zero.');
            }
            if ($change !== 0) {
                $this->move($locked, $change, $mode === 'add' ? 'restock' : 'adjust', null, $by, $note);
            }

            return $locked->fresh();
        });
    }

    private function move(MenuItem $item, int $change, string $reason, ?Order $order, ?User $by, ?string $note = null): void
    {
        $before = $item->stock_qty;
        $item->stock_qty += $change;
        $item->save();

        // Sold out, or back in stock: tell the WhatsApp catalog so the item shows as unavailable/available.
        if (($before > 0) !== ($item->stock_qty > 0) && $item->catalog_synced_at) {
            \App\Jobs\SyncCatalogItem::dispatch($item->id)->afterCommit();
        }

        StockMovement::query()->create([
            'menu_item_id' => $item->id,
            'change' => $change,
            'balance_after' => $item->stock_qty,
            'reason' => $reason,
            'order_id' => $order?->id,
            'user_id' => $by?->id,
            'note' => $note ? mb_substr($note, 0, 255) : null,
        ]);
    }
}
