<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\WhatsappMessage;
use App\Services\CartException;
use App\Services\OrderService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:60'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = Order::query()->with(['customer', 'diningTable'])->withSum('items as items_quantity', 'quantity')->latest('id');

        $status = $filters['status'] ?? 'all';
        if ($status !== OrderStatus::AwaitingPayment->value) {
            $query->visible(); // unpaid online orders stay out of the normal lists
        }
        if ($status === 'active') {
            $query->active();
        } elseif ($s = OrderStatus::tryFrom($status)) {
            $query->where('status', $s->value);
        }

        if ($q = trim($filters['q'] ?? '')) {
            $digits = preg_replace('/\D/', '', $q);
            $query->where(function ($w) use ($q, $digits) {
                $w->where('order_number', 'like', '%'.$q.'%')
                    ->orWhere('guest_name', 'like', '%'.$q.'%')
                    ->orWhereHas('customer', function ($c) use ($q, $digits) {
                        $c->where('name', 'like', '%'.$q.'%');
                        if (strlen($digits) >= 4) {
                            $c->orWhere('wa_id', 'like', '%'.$digits.'%');
                        }
                    });
            });
        }

        if (! empty($filters['date'])) {
            [$from, $to] = LocalTime::dayRangeUtc(CarbonImmutable::createFromFormat('Y-m-d', $filters['date'], LocalTime::tz()));
            $query->where('created_at', '>=', $from)->where('created_at', '<', $to);
        }

        $counts = Order::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $awaiting = (int) ($counts[OrderStatus::AwaitingPayment->value] ?? 0);
        $counts->forget(OrderStatus::AwaitingPayment->value);

        return view('admin.orders.index', [
            'orders' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'q' => $filters['q'] ?? '',
            'date' => $filters['date'] ?? '',
            'counts' => $counts,
            'activeCount' => collect(OrderStatus::active())->sum(fn ($s) => $counts[$s->value] ?? 0),
            'awaitingCount' => $awaiting,
        ]);
    }

    public function show(Order $order): View
    {
        $order->load(['customer', 'items', 'histories.user', 'notifications', 'diningTable', 'creator']);

        $messages = $order->customer
            ? WhatsappMessage::query()->where('wa_id', $order->customer->wa_id)->latest('id')->limit(30)->get()->reverse()->values()
            : collect();

        return view('admin.orders.show', [
            'order' => $order,
            'next' => $order->status->nextStatuses($order->order_type),
            'messages' => $messages,
        ]);
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'note' => ['nullable', 'string', 'max:255'],
            'redirect' => ['nullable', Rule::in(['kitchen', 'order'])],
        ]);
        $to = OrderStatus::from($data['status']);

        if ($to === OrderStatus::Cancelled && blank($data['note'] ?? null)) {
            return back()->withErrors(['note' => 'Write a reason for cancelling. The customer will see it.']);
        }

        try {
            $order = $this->orders->changeStatus($order, $to, $request->user(), $data['note'] ?? null);
        } catch (CartException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $msg = "Order {$order->order_number} is now {$to->label()}.";
        if (in_array($to, OrderService::NOTIFY_ON, true)) {
            $msg .= ' Customer update queued on WhatsApp.';
        }

        return ($data['redirect'] ?? null) === 'kitchen'
            ? redirect()->route('kitchen')->with('status', $msg)
            : redirect()->route('orders.show', $order)->with('status', $msg);
    }

    public function markPaid(Request $request, Order $order): RedirectResponse
    {
        $method = $request->validate(['payment_method' => ['nullable', Rule::enum(\App\Enums\PaymentMethod::class)]])['payment_method'] ?? null;
        try {
            $this->orders->markPaid($order, $request->user(), $method ? \App\Enums\PaymentMethod::from($method) : null);
        } catch (CartException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('status', "Order {$order->order_number} marked as paid.");
    }

    public function retryNotification(Order $order, OrderNotification $notification): RedirectResponse
    {
        if ($notification->state === 'sent') {
            return back()->with('status', 'This update was already sent.');
        }
        $this->orders->retryNotification($notification);

        return back()->with('status', 'WhatsApp update queued again.');
    }
}
