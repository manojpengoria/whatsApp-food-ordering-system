<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class CartService
{
    public const MAX_QTY_PER_ITEM = 50;
    public const MAX_LINES = 30;

    public function __construct(private readonly Settings $settings) {}

    public function upsertCustomer(string $waId, ?string $name = null): Customer
    {
        $customer = Customer::query()->firstOrCreate(['wa_id' => $waId], ['name' => $name]);
        if ($name && $customer->name !== $name) {
            $customer->update(['name' => $name]);
        }

        return $customer;
    }

    public function cartFor(Customer $customer): Cart
    {
        return Cart::query()->firstOrCreate(['customer_id' => $customer->id]);
    }

    /** Adds to the existing quantity. Throws CartException on invalid item/qty. */
    public function addItem(Customer $customer, string $code, int $quantity): Cart
    {
        $item = $this->findOrderableItem($code);
        if ($quantity < 1) {
            throw new CartException('invalid_quantity', 'Quantity must be at least 1.');
        }

        return DB::transaction(function () use ($customer, $item, $quantity) {
            $cart = $this->cartFor($customer);
            $line = CartItem::query()->where('cart_id', $cart->id)->where('menu_item_id', $item->id)->lockForUpdate()->first();

            $newQty = ($line?->quantity ?? 0) + $quantity;
            if ($item->track_stock && $newQty > $item->stock_qty) {
                throw new CartException('out_of_stock', "Only {$item->stock_qty} × {$item->name} left.");
            }
            if ($newQty > self::MAX_QTY_PER_ITEM) {
                throw new CartException('quantity_limit', 'You can order at most '.self::MAX_QTY_PER_ITEM.' of one item.');
            }
            if (! $line && $cart->items()->count() >= self::MAX_LINES) {
                throw new CartException('cart_full', 'Your cart is full.');
            }

            CartItem::query()->updateOrCreate(
                ['cart_id' => $cart->id, 'menu_item_id' => $item->id],
                ['quantity' => $newQty],
            );
            $cart->touch();

            return $cart;
        });
    }

    /** Sets the quantity exactly; 0 removes the line. */
    public function setQuantity(Customer $customer, string $code, int $quantity): Cart
    {
        if ($quantity < 0 || $quantity > self::MAX_QTY_PER_ITEM) {
            throw new CartException('invalid_quantity', 'Quantity must be between 0 and '.self::MAX_QTY_PER_ITEM.'.');
        }
        $item = MenuItem::query()->where('code', $code)->first();
        if (! $item) {
            throw new CartException('item_not_found', "No item with code {$code}.");
        }

        $cart = $this->cartFor($customer);
        if ($quantity === 0) {
            CartItem::query()->where('cart_id', $cart->id)->where('menu_item_id', $item->id)->delete();
        } else {
            if (! $item->isOrderable()) {
                throw new CartException('item_unavailable', "{$item->name} is not available right now.");
            }
            if ($item->track_stock && $quantity > $item->stock_qty) {
                throw new CartException('out_of_stock', "Only {$item->stock_qty} × {$item->name} left.");
            }
            CartItem::query()->updateOrCreate(['cart_id' => $cart->id, 'menu_item_id' => $item->id], ['quantity' => $quantity]);
        }
        $cart->touch();

        return $cart;
    }

    /**
     * A WhatsApp catalog "Send cart" is the customer's whole cart, so it REPLACES ours.
     * Retailer ids are "wm_<menu item id>" (synced by us) or, for products made by hand
     * in Commerce Manager, the item's WhatsApp code.
     *
     * @param  list<array{retailer_id: string, quantity: int}>  $lines
     * @return array{0: Cart, 1: list<string>, 2: list<array{name: string, wanted: int, given: int}>} [cart, rejected, reduced]
     */
    public function replaceFromCatalog(Customer $customer, array $lines): array
    {
        return DB::transaction(function () use ($customer, $lines) {
            $cart = $this->cartFor($customer);
            Cart::query()->whereKey($cart->id)->lockForUpdate()->first();
            $cart->items()->delete();

            $rejected = [];
            $quantities = [];
            foreach ($lines as $line) {
                $rid = trim((string) $line['retailer_id']);
                $item = preg_match('/^wm_(\d+)$/', $rid, $m)
                    ? MenuItem::query()->with('category')->find((int) $m[1])
                    : MenuItem::query()->with('category')->where('code', strtoupper($rid))->first();

                if (! $item || ! $item->isOrderable()) {
                    $rejected[] = $item?->name ?? $rid;
                    continue;
                }
                $quantities[$item->id] = min(self::MAX_QTY_PER_ITEM, ($quantities[$item->id] ?? 0) + max(1, (int) $line['quantity']));
            }

            // WhatsApp lets customers add up to 99 of anything; we trim to what is actually in stock.
            $reduced = [];
            $items = MenuItem::query()->whereIn('id', array_keys($quantities))->get()->keyBy('id');
            foreach (array_slice($quantities, 0, self::MAX_LINES, true) as $menuItemId => $qty) {
                $item = $items[$menuItemId];
                if ($item->track_stock && $qty > $item->stock_qty) {
                    $reduced[] = ['name' => $item->name, 'wanted' => $qty, 'given' => $item->stock_qty];
                    $qty = $item->stock_qty;
                }
                if ($qty > 0) {
                    CartItem::query()->create(['cart_id' => $cart->id, 'menu_item_id' => $menuItemId, 'quantity' => $qty]);
                }
            }
            $cart->touch();

            return [$cart, $rejected, $reduced];
        });
    }

    public function clear(Customer $customer): void
    {
        $cart = $this->cartFor($customer);
        $cart->items()->delete();
        $cart->touch();
    }

    /**
     * Cart priced with CURRENT menu prices. Unavailable items are listed separately and not charged.
     */
    public function summary(Cart $cart, ?OrderType $type = null): array
    {
        $cart->load(['items.menuItem.category']);

        $lines = [];
        $unavailable = [];
        $subtotal = 0;

        foreach ($cart->items->sortBy('id') as $line) {
            $item = $line->menuItem;
            if (! $item) {
                continue;
            }
            if (! $item->isOrderable()) {
                $unavailable[] = ['code' => $item->code, 'name' => $item->name];
                continue;
            }
            $lineTotal = $item->price_paise * $line->quantity;
            $subtotal += $lineTotal;
            $lines[] = [
                'code' => $item->code,
                'name' => $item->name,
                'quantity' => $line->quantity,
                'unit_price_paise' => $item->price_paise,
                'line_total_paise' => $lineTotal,
                'unit_price' => Money::format($item->price_paise),
                'line_total' => Money::format($lineTotal),
            ];
        }

        $totals = $this->totals($subtotal, $type);

        return [
            'items' => $lines,
            'unavailable_items' => $unavailable,
            'item_count' => array_sum(array_column($lines, 'quantity')),
            'is_empty' => $lines === [],
            'order_type' => $type?->value,
        ] + $totals;
    }

    public function totals(int $subtotal, ?OrderType $type): array
    {
        $taxBp = $this->settings->int('tax_rate_bp');
        $tax = intdiv($subtotal * $taxBp + 5000, 10000); // round half up
        $delivery = ($type === OrderType::Delivery && $subtotal > 0) ? $this->settings->int('delivery_fee_paise') : 0;
        $total = $subtotal + $tax + $delivery;

        return [
            'subtotal_paise' => $subtotal,
            'tax_rate_bp' => $taxBp,
            'tax_paise' => $tax,
            'delivery_fee_paise' => $delivery,
            'total_paise' => $total,
            'subtotal' => Money::format($subtotal),
            'tax' => Money::format($tax),
            'tax_label' => 'GST '.rtrim(rtrim(number_format($taxBp / 100, 2), '0'), '.').'%',
            'delivery_fee' => Money::format($delivery),
            'total' => Money::format($total),
        ];
    }

    private function findOrderableItem(string $code): MenuItem
    {
        $item = MenuItem::query()->with('category')->where('code', $code)->first();
        if (! $item) {
            throw new CartException('item_not_found', "No item with code {$code}.");
        }
        if (! $item->isOrderable()) {
            throw new CartException('item_unavailable', $item->isOutOfStock() ? "{$item->name} is sold out." : "{$item->name} is not available right now.");
        }

        return $item;
    }
}
