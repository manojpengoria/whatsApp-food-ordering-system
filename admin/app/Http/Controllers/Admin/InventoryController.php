<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Services\CartException;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->query('filter', 'all');
        $items = MenuItem::query()->with('category')->orderBy('menu_category_id')->orderBy('sort_order')->get();

        $tracked = $items->where('track_stock', true);
        $shown = match ($filter) {
            'tracked' => $tracked,
            'low' => $items->filter->isLowStock(),
            'out' => $items->filter->isOutOfStock(),
            default => $items,
        };

        return view('admin.inventory.index', [
            'items' => $shown,
            'filter' => $filter,
            'counts' => [
                'all' => $items->count(),
                'tracked' => $tracked->count(),
                'low' => $items->filter->isLowStock()->count(),
                'out' => $items->filter->isOutOfStock()->count(),
            ],
        ]);
    }

    public function adjust(Request $request, MenuItem $item, StockService $stock): RedirectResponse
    {
        if (! $item->track_stock) {
            return back()->withErrors(['stock' => "Turn on stock tracking for {$item->name} first."]);
        }
        $data = $request->validate([
            'mode' => ['required', Rule::in(['add', 'remove', 'set'])],
            'quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $item = $stock->adjust($item, $data['mode'], (int) $data['quantity'], $request->user(), $data['note'] ?? null);
        } catch (CartException $e) {
            return back()->withErrors(['stock' => $e->getMessage()]);
        }

        return back()->with('status', "{$item->name}: stock is now {$item->stock_qty}.");
    }

    public function settings(Request $request, MenuItem $item): RedirectResponse
    {
        $data = $request->validate(['low_stock_at' => ['required', 'integer', 'min:0', 'max:10000']]);
        $item->update(['track_stock' => $request->boolean('track_stock'), 'low_stock_at' => $data['low_stock_at']]);

        return back()->with('status', $item->track_stock
            ? "Stock tracking is on for {$item->name}. Set the current count with “Set to”."
            : "Stock tracking is off for {$item->name}. It can always be ordered.");
    }

    public function history(MenuItem $item): View
    {
        return view('admin.inventory.history', [
            'item' => $item,
            'moves' => $item->stockMovements()->with(['order', 'user'])->paginate(30),
        ]);
    }
}
