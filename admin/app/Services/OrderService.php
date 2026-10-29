<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Jobs\SendOrderNotification;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /** Status changes that the customer is told about on WhatsApp. */
    public const NOTIFY_ON = [
        OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready,
        OrderStatus::OutForDelivery, OrderStatus::Completed, OrderStatus::Cancelled,
    ];

    public function __construct(
        private readonly CartService $carts,
        private readonly Settings $settings,
        private readonly StockService $stock,
    ) {}

    /**
     * Turns the customer's cart into an order. Safe to retry with the same idempotency key.
     *
     * @return array{0: Order, 1: bool} [order, created]
     */
    public function placeFromCart(
        Customer $customer,
        OrderType $type,
        ?string $address,
        ?string $notes,
        string $idempotencyKey,
        PaymentMethod $method = PaymentMethod::Cash,
    ): array {
        $existing = Order::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->customer_id !== $customer->id) {
                throw new CartException('idempotency_conflict', 'This request id was already used.', 409);
            }

            return [$existing, false];
        }

        if (! $this->settings->canTakeOrders()) {
            throw new CartException('restaurant_closed', 'Sorry, we are not taking orders right now. Hours: '.$this->settings->hoursLabel().'.', 409);
        }
        if ($type === OrderType::Delivery && ! $this->settings->bool('delivery_enabled')) {
            throw new CartException('delivery_unavailable', 'Delivery is not available right now. Please choose takeaway.', 409);
        }
        if ($type === OrderType::Delivery && mb_strlen(trim((string) $address)) < 10) {
            throw new CartException('address_required', 'Please send your full delivery address.');
        }

        if (! in_array($method->value, $this->settings->whatsappPaymentMethods(), true)) {
            throw new CartException('payment_method_unavailable', 'This payment option is not available right now.');
        }

        [$order, $created] = DB::transaction(function () use ($customer, $type, $address, $notes, $idempotencyKey, $method) {
            // Lock the cart so two parallel checkouts can't both use it.
            $cart = Cart::query()->where('customer_id', $customer->id)->lockForUpdate()->first();
            if (! $cart) {
                throw new CartException('cart_empty', 'Your cart is empty.');
            }

            $summary = $this->carts->summary($cart, $type);
            if ($summary['is_empty']) {
                throw new CartException('cart_empty', 'Your cart is empty.');
            }
            if ($summary['unavailable_items'] !== []) {
                $names = implode(', ', array_column($summary['unavailable_items'], 'name'));
                throw new CartException('items_unavailable', "These items are no longer available: {$names}. Please remove them and try again.", 409);
            }

            $order = Order::query()->create([
                'customer_id' => $customer->id,
                // Online payment: staff only see the order once it is paid.
                'status' => $method->isOnline() ? OrderStatus::AwaitingPayment : OrderStatus::Pending,
                'visible_at' => $method->isOnline() ? null : now(),
                'order_type' => $type,
                'source' => 'whatsapp',
                'delivery_address' => $type === OrderType::Delivery ? trim((string) $address) : null,
                'notes' => $notes ? mb_substr(trim($notes), 0, 500) : null,
                'subtotal_paise' => $summary['subtotal_paise'],
                'tax_rate_bp' => $summary['tax_rate_bp'],
                'tax_paise' => $summary['tax_paise'],
                'delivery_fee_paise' => $summary['delivery_fee_paise'],
                'total_paise' => $summary['total_paise'],
                'payment_method' => $method,
                'payment_status' => PaymentStatus::Unpaid,
                'idempotency_key' => $idempotencyKey,
            ]);
            $number = config('restaurant.order_prefix').(1000 + $order->id);
            $order->forceFill([
                'order_number' => $number,
                'payment_reference' => $method === PaymentMethod::WhatsappPay ? $number.'-1' : null,
            ])->save();

            foreach ($summary['items'] as $line) {
                $order->items()->create([
                    'menu_item_id' => $cart->items->firstWhere('menuItem.code', $line['code'])?->menu_item_id,
                    'item_name' => $line['name'],
                    'item_code' => $line['code'],
                    'unit_price_paise' => $line['unit_price_paise'],
                    'quantity' => $line['quantity'],
                    'line_total_paise' => $line['line_total_paise'],
                ]);
            }

            $quantities = [];
            foreach ($order->items()->get() as $line) {
                if ($line->menu_item_id) {
                    $quantities[$line->menu_item_id] = ($quantities[$line->menu_item_id] ?? 0) + $line->quantity;
                }
            }
            $this->stock->reserveForOrder($order, $quantities);

            $order->histories()->create([
                'from_status' => null,
                'to_status' => $order->status,
                'note' => $method->isOnline() ? 'Placed on WhatsApp — waiting for online payment' : 'Placed on WhatsApp',
            ]);
            if ($method->isOnline()) {
                \App\Jobs\ExpireUnpaidOrder::dispatch($order->id)
                    ->delay(now()->addMinutes($this->settings->paymentWindowMinutes())->addSeconds(30))
                    ->afterCommit();
            }

            if ($type === OrderType::Delivery) {
                $customer->update(['last_delivery_address' => $order->delivery_address]);
            }

            $cart->items()->delete();

            return [$order->fresh(['items', 'customer']), true];
        });

        // Online payment link is created after the order is safely saved. If Razorpay is down,
        // the order still exists; the bot tells the customer and staff can collect payment later.
        if ($method === PaymentMethod::Razorpay) {
            try {
                app(RazorpayService::class)->createPaymentLink($order);
            } catch (CartException $e) {
                \Illuminate\Support\Facades\Log::warning('Payment link failed', ['order' => $order->order_number, 'error' => $e->getMessage()]);
            }
        }

        return [$order->fresh(['items', 'customer']), $created];
    }

    /** Moves an order to a new status (admin action) and queues the WhatsApp update. */
    public function changeStatus(Order $order, OrderStatus $to, ?User $by = null, ?string $note = null): Order
    {
        $order = DB::transaction(function () use ($order, $to, $by, $note) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canMoveTo($to, $locked->order_type)) {
                throw new CartException('invalid_transition', "Order {$locked->order_number} can't move from {$from->label()} to {$to->label()}.", 409);
            }

            $updates = ['status' => $to];
            if ($to === OrderStatus::Cancelled) {
                $updates['cancel_reason'] = $note ? mb_substr($note, 0, 255) : null;
            }
            $locked->update($updates);
            $locked->histories()->create([
                'from_status' => $from,
                'to_status' => $to,
                'user_id' => $by?->id,
                'note' => $note ? mb_substr($note, 0, 255) : null,
            ]);

            if ($to === OrderStatus::Cancelled) {
                $this->stock->releaseForOrder($locked, $by);
            }

            if (in_array($to, self::NOTIFY_ON, true) && $locked->customer_id && $locked->source === 'whatsapp') {
                $notification = $locked->notifications()->create([
                    'event' => 'order.status_changed',
                    'status_snapshot' => $to->value,
                    'state' => 'pending',
                ]);
                // Dispatch only after the transaction commits, so the job sees the new status.
                SendOrderNotification::dispatch($notification->id)->afterCommit();
            }

            return $locked;
        });

        return $order->fresh();
    }

    /** The gateway confirmed payment. Makes the order visible to staff. Safe to call twice. */
    public function markPaidOnline(Order $order, string $paymentRef, ?PaymentMethod $method = null): Order
    {
        $order = DB::transaction(function () use ($order, $paymentRef, $method) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_status === PaymentStatus::Paid) {
                return $locked;
            }
            $method ??= $locked->payment_method;
            $from = $locked->status;
            $updates = [
                'payment_status' => PaymentStatus::Paid,
                'payment_method' => $method,
                'paid_at' => now(),
                'payment_ref' => mb_substr($paymentRef, 0, 64),
            ];
            if ($from === OrderStatus::AwaitingPayment) {
                $updates['status'] = OrderStatus::Pending;   // now it appears in the panel (with the alarm)
                $updates['visible_at'] = now();
            }
            $locked->update($updates);
            $locked->histories()->create([
                'from_status' => $from,
                'to_status' => $locked->status,
                'note' => 'Paid online ('.$method->label().' '.$paymentRef.')',
            ]);
            if ($locked->customer_id) {
                $n = $locked->notifications()->create(['event' => 'order.payment_received', 'status_snapshot' => $locked->status->value, 'state' => 'pending']);
                SendOrderNotification::dispatch($n->id)->afterCommit();
            }

            return $locked;
        });

        return $order->fresh();
    }

    /** Payment window ran out: cancel quietly for staff, put stock back, tell the customer. */
    public function expireUnpaid(Order $order, string $reason = 'Payment not received in time', bool $notify = true): Order
    {
        $order = DB::transaction(function () use ($order, $reason, $notify) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== OrderStatus::AwaitingPayment) {
                return $locked;
            }
            $locked->update(['status' => OrderStatus::Cancelled, 'cancel_reason' => $reason]);
            $locked->histories()->create(['from_status' => OrderStatus::AwaitingPayment, 'to_status' => OrderStatus::Cancelled, 'note' => $reason]);
            $this->stock->releaseForOrder($locked);
            if ($notify && $locked->customer_id) {
                $n = $locked->notifications()->create(['event' => 'order.payment_expired', 'status_snapshot' => 'cancelled', 'state' => 'pending']);
                SendOrderNotification::dispatch($n->id)->afterCommit();
            }

            return $locked;
        });
        if ($order->payment_method === PaymentMethod::Razorpay) {
            app(RazorpayService::class)->cancelLink($order);
        }

        return $order->fresh();
    }

    /**
     * Billing screen: an order taken at the counter or a table. Goes straight to the kitchen (Confirmed).
     *
     * @param  array<int, int>  $lines  menu_item_id => quantity
     */
    public function placeCounterOrder(
        array $lines,
        OrderType $type,
        ?int $tableId,
        ?string $guestName,
        ?string $guestPhone,
        PaymentMethod $method,
        bool $paidNow,
        ?string $notes,
        User $by,
    ): Order {
        $lines = array_filter($lines, fn ($q) => $q > 0);
        if ($lines === []) {
            throw new CartException('empty_bill', 'Add at least one item to the bill.');
        }
        if ($type === OrderType::DineIn && ! $tableId) {
            throw new CartException('table_required', 'Choose a table for a dine-in order.');
        }
        if ($type === OrderType::Delivery) {
            throw new CartException('delivery_not_counter', 'Delivery orders come from WhatsApp. Use dine-in or takeaway here.');
        }

        return DB::transaction(function () use ($lines, $type, $tableId, $guestName, $guestPhone, $method, $paidNow, $notes, $by) {
            $items = \App\Models\MenuItem::query()->with('category')->whereIn('id', array_keys($lines))->get()->keyBy('id');
            $subtotal = 0;
            $rows = [];
            foreach ($lines as $id => $qty) {
                $item = $items->get($id);
                if (! $item) {
                    throw new CartException('item_not_found', 'An item on the bill no longer exists.');
                }
                if ($qty > CartService::MAX_QTY_PER_ITEM) {
                    throw new CartException('quantity_limit', "At most ".CartService::MAX_QTY_PER_ITEM." × {$item->name}.");
                }
                $line = $item->price_paise * $qty;
                $subtotal += $line;
                $rows[] = [
                    'menu_item_id' => $item->id, 'item_name' => $item->name, 'item_code' => $item->code,
                    'unit_price_paise' => $item->price_paise, 'quantity' => $qty, 'line_total_paise' => $line,
                ];
            }
            $totals = $this->carts->totals($subtotal, $type);

            $order = Order::query()->create([
                'customer_id' => null,
                'dining_table_id' => $type === OrderType::DineIn ? $tableId : null,
                'guest_name' => $guestName ? mb_substr(trim($guestName), 0, 120) : null,
                'guest_phone' => $guestPhone ? mb_substr(trim($guestPhone), 0, 20) : null,
                'status' => OrderStatus::Confirmed,
                'order_type' => $type,
                'source' => 'pos',
                'notes' => $notes ? mb_substr(trim($notes), 0, 500) : null,
                'subtotal_paise' => $totals['subtotal_paise'],
                'tax_rate_bp' => $totals['tax_rate_bp'],
                'tax_paise' => $totals['tax_paise'],
                'delivery_fee_paise' => 0,
                'total_paise' => $totals['total_paise'],
                'payment_method' => $method,
                'payment_status' => $paidNow ? PaymentStatus::Paid : PaymentStatus::Unpaid,
                'paid_at' => $paidNow ? now() : null,
                'created_by' => $by->id,
            ]);
            $order->forceFill(['order_number' => config('restaurant.order_prefix').(1000 + $order->id)])->save();
            $order->items()->createMany($rows);

            $this->stock->reserveForOrder($order, $lines, $by);

            $order->histories()->create(['from_status' => null, 'to_status' => OrderStatus::Confirmed, 'user_id' => $by->id, 'note' => 'Billed at the counter']);

            return $order->fresh(['items', 'diningTable']);
        });
    }

    public function markPaid(Order $order, ?User $by = null, ?PaymentMethod $method = null): Order
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw new CartException('order_cancelled', 'A cancelled order cannot be marked as paid.', 409);
        }
        if ($order->payment_status !== PaymentStatus::Paid) {
            $order->update(array_filter([
                'payment_status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'payment_method' => $method,
            ]));
            $order->histories()->create([
                'from_status' => $order->status, 'to_status' => $order->status,
                'user_id' => $by?->id, 'note' => 'Payment received ('.$order->payment_method->label().')',
            ]);
        }

        return $order->fresh();
    }

    public function retryNotification(OrderNotification $notification): void
    {
        $notification->update(['state' => 'pending', 'last_error' => null]);
        SendOrderNotification::dispatch($notification->id);
    }
}
