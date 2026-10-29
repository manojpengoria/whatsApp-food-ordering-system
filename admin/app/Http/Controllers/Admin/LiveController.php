<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Polled by every admin page every few seconds. Returns WhatsApp orders newer than the
 * last one the page knows about, and online payments received since the last poll.
 */
class LiveController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'since' => ['nullable', 'integer', 'min:0'],   // unix seconds of previous poll
        ]);
        $after = (int) ($data['after'] ?? 0);
        $since = isset($data['since']) ? CarbonImmutable::createFromTimestamp((int) $data['since']) : now()->subSeconds(10);

        // "New" = became visible to staff since the last poll (cash orders at once, online orders once paid).
        $new = Order::query()->with('customer')->withSum('items as items_quantity', 'quantity')
            ->where('source', 'whatsapp')->whereNotNull('visible_at')
            ->where('visible_at', '>', $since)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->orderBy('visible_at')->limit(10)->get();

        // Payments for orders staff could already see (e.g. paid later); fresh paid orders show as "new".
        $paid = Order::query()->with('customer')
            ->whereIn('payment_method', [PaymentMethod::Razorpay->value, PaymentMethod::WhatsappPay->value])
            ->where('paid_at', '>', $since)->whereColumn('visible_at', '<', 'paid_at')->limit(10)->get();

        return response()->json([
            'latest_id' => (int) (Order::query()->max('id') ?? 0),
            'now' => now()->timestamp,
            'pending_count' => Order::query()->where('status', OrderStatus::Pending->value)->count(),
            'new_orders' => $new->map(fn (Order $o) => [
                'number' => $o->order_number,
                'customer' => $o->customer_label,
                'type' => $o->order_type->label(),
                'items' => (int) $o->items_count,
                'total' => $o->total,
                'payment' => $o->payment_method->label().($o->payment_status->value === 'paid' ? ' · Paid' : ''),
                'url' => route('orders.show', $o),
            ])->values(),
            'payments' => $paid->map(fn (Order $o) => [
                'number' => $o->order_number,
                'total' => $o->total,
                'url' => route('orders.show', $o),
            ])->values(),
        ]);
    }
}
