<?php

namespace App\Http\Controllers\Internal;

use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Services\CartException;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\Settings;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON API used by the Node WhatsApp bot. Every business rule (prices, totals,
 * availability, hours) lives here; the bot only formats replies.
 */
class BotApiController extends Controller
{
    private const WA_ID_RULE = ['required', 'string', 'regex:/^\d{8,15}$/'];

    public function __construct(
        private readonly Settings $settings,
        private readonly CartService $carts,
        private readonly OrderService $orders,
    ) {}

    public function restaurant(): JsonResponse
    {
        return response()->json([
            'name' => $this->settings->get('restaurant_name'),
            'phone' => $this->settings->get('restaurant_phone'),
            'address' => $this->settings->get('restaurant_address'),
            'hours' => $this->settings->hoursLabel(),
            'is_open' => $this->settings->isOpenAt(),
            'accepting_orders' => $this->settings->canTakeOrders(),
            'delivery_enabled' => $this->settings->bool('delivery_enabled'),
            'delivery_fee' => Money::format($this->settings->int('delivery_fee_paise')),
            'tax_label' => $this->carts->totals(0, null)['tax_label'],
            'sales_contact' => $this->settings->get('sales_contact'),
            // Photo catalog in WhatsApp: on only after admin turned it on and something is synced.
            'catalog_enabled' => $this->settings->bool('catalog_enabled')
                && \App\Models\MenuItem::query()->whereNotNull('catalog_synced_at')->exists(),
            'payment_methods' => $this->settings->whatsappPaymentMethods(),
            'payment_window_minutes' => $this->settings->paymentWindowMinutes(),
            'wa_pay_config_name' => $this->settings->get('wa_pay_config_name'),
            'catalog_thumbnail_retailer_id' => \App\Models\MenuItem::query()
                ->whereNotNull('catalog_synced_at')->where('is_available', true)
                ->where(fn ($w) => $w->where('track_stock', false)->orWhere('stock_qty', '>', 0))->orderBy('id')->first()?->retailer_id,
        ]);
    }

    public function menu(): JsonResponse
    {
        $categories = MenuCategory::query()
            ->where('is_active', true)
            ->ordered()
            ->with(['items' => fn ($q) => $q->where('is_available', true)
                ->where(fn ($w) => $w->where('track_stock', false)->orWhere('stock_qty', '>', 0))])
            ->get()
            ->filter(fn ($c) => $c->items->isNotEmpty())
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'items' => $c->items->map(fn ($i) => [
                    'code' => $i->code,
                    'name' => $i->name,
                    'description' => $i->description,
                    'price_paise' => $i->price_paise,
                    'price' => $i->price,
                    'is_veg' => $i->is_veg,
                    'retailer_id' => $i->retailer_id,
                    'in_catalog' => $i->catalog_synced_at !== null,
                ])->values(),
            ])->values();

        return response()->json(['categories' => $categories]);
    }

    public function upsertCustomer(Request $request): JsonResponse
    {
        $data = $request->validate(['wa_id' => self::WA_ID_RULE, 'name' => ['nullable', 'string', 'max:120']]);
        $customer = $this->carts->upsertCustomer($data['wa_id'], $data['name'] ?? null);

        return response()->json(['customer' => $this->customerJson($customer)]);
    }

    public function showCart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'wa_id' => self::WA_ID_RULE,
            'order_type' => ['nullable', Rule::enum(OrderType::class)],
        ]);
        $customer = $this->carts->upsertCustomer($data['wa_id']);
        $type = isset($data['order_type']) ? OrderType::from($data['order_type']) : null;

        return response()->json(['cart' => $this->carts->summary($this->carts->cartFor($customer), $type)]);
    }

    public function addCartItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'wa_id' => self::WA_ID_RULE,
            'name' => ['nullable', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:10'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_QTY_PER_ITEM],
        ]);

        return $this->cartAction(function () use ($data) {
            $customer = $this->carts->upsertCustomer($data['wa_id'], $data['name'] ?? null);

            return $this->carts->addItem($customer, strtoupper($data['code']), (int) $data['quantity']);
        });
    }

    public function setCartItem(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'wa_id' => self::WA_ID_RULE,
            'quantity' => ['required', 'integer', 'min:0', 'max:'.CartService::MAX_QTY_PER_ITEM],
        ]);

        return $this->cartAction(function () use ($data, $code) {
            $customer = $this->carts->upsertCustomer($data['wa_id']);

            return $this->carts->setQuantity($customer, strtoupper($code), (int) $data['quantity']);
        });
    }

    /** WhatsApp catalog "Send cart" -> replaces the cart. */
    public function replaceCart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'wa_id' => self::WA_ID_RULE,
            'name' => ['nullable', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.retailer_id' => ['required', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        $customer = $this->carts->upsertCustomer($data['wa_id'], $data['name'] ?? null);
        [$cart, $rejected, $reduced] = $this->carts->replaceFromCatalog($customer, $data['items']);

        return response()->json(['cart' => $this->carts->summary($cart), 'rejected' => $rejected, 'reduced' => $reduced]);
    }

    public function clearCart(Request $request): JsonResponse
    {
        $data = $request->validate(['wa_id' => self::WA_ID_RULE]);
        $customer = $this->carts->upsertCustomer($data['wa_id']);
        $this->carts->clear($customer);

        return response()->json(['cart' => $this->carts->summary($this->carts->cartFor($customer))]);
    }

    public function listOrders(Request $request): JsonResponse
    {
        $data = $request->validate(['wa_id' => self::WA_ID_RULE, 'limit' => ['nullable', 'integer', 'min:1', 'max:10']]);
        $customer = Customer::query()->where('wa_id', $data['wa_id'])->first();

        $orders = $customer
            ? $customer->orders()->with('items')->latest('id')->limit($data['limit'] ?? 3)->get()
            : collect();

        return response()->json(['orders' => $orders->map(fn (Order $o) => $this->orderJson($o))->values()]);
    }

    public function placeOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'wa_id' => self::WA_ID_RULE,
            'name' => ['nullable', 'string', 'max:120'],
            'order_type' => ['required', Rule::enum(OrderType::class)],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'payment_method' => ['nullable', Rule::enum(\App\Enums\PaymentMethod::class)],
        ]);

        try {
            $customer = $this->carts->upsertCustomer($data['wa_id'], $data['name'] ?? null);
            [$order, $created] = $this->orders->placeFromCart(
                $customer,
                OrderType::from($data['order_type']),
                $data['delivery_address'] ?? null,
                $data['notes'] ?? null,
                $data['idempotency_key'],
                \App\Enums\PaymentMethod::from($data['payment_method'] ?? 'cash'),
            );
        } catch (CartException $e) {
            return $this->error($e);
        }

        return response()->json(['order' => $this->orderJson($order), 'created' => $created], $created ? 201 : 200);
    }

    private function cartAction(callable $fn): JsonResponse
    {
        try {
            $cart = $fn();
        } catch (CartException $e) {
            return $this->error($e);
        }

        return response()->json(['cart' => $this->carts->summary($cart)]);
    }

    private function error(CartException $e): JsonResponse
    {
        return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $e->httpStatus);
    }

    private function customerJson(Customer $c): array
    {
        return ['wa_id' => $c->wa_id, 'name' => $c->name, 'last_delivery_address' => $c->last_delivery_address];
    }

    private function orderJson(Order $o): array
    {
        return [
            'number' => $o->order_number,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'order_type' => $o->order_type->value,
            'delivery_address' => $o->delivery_address,
            'items' => $o->items->map(fn ($i) => [
                'name' => $i->item_name,
                'quantity' => $i->quantity,
                'line_total' => Money::format($i->line_total_paise),
            ])->values(),
            'subtotal' => Money::format($o->subtotal_paise),
            'tax' => Money::format($o->tax_paise),
            'delivery_fee' => Money::format($o->delivery_fee_paise),
            'total' => Money::format($o->total_paise),
            'total_paise' => $o->total_paise,
            'payment_method' => $o->payment_method->label(),
            'payment_status' => $o->payment_status->value,
            'payment' => [
                'method' => $o->payment_method->value,
                'link_url' => $o->payment_link_url,
                'reference_id' => $o->payment_reference,
                'window_minutes' => $this->settings->paymentWindowMinutes(),
                // Ready-made parameters for the WhatsApp Pay order_details message.
                'order_details' => $o->payment_method === \App\Enums\PaymentMethod::WhatsappPay
                    && $o->status === \App\Enums\OrderStatus::AwaitingPayment
                    ? app(\App\Services\WhatsappPayService::class)->parameters($o) : null,
            ],
            'placed_at' => $o->created_at?->toIso8601String(),
        ];
    }

    /**
     * Razorpay webhook, forwarded by the bot (the tunnel points at the bot).
     * Body: {"raw": "<exact request body>", "signature": "<X-Razorpay-Signature>"}
     */
    public function razorpayWebhook(Request $request, \App\Services\RazorpayService $razorpay): JsonResponse
    {
        $raw = (string) $request->input('raw', '');
        $sig = (string) $request->input('signature', '');
        if (! $razorpay->validWebhookSignature($raw, $sig)) {
            return response()->json(['error' => 'bad_signature', 'message' => 'Signature check failed (check the webhook secret).'], 401);
        }

        $event = json_decode($raw, true) ?: [];
        if (($event['event'] ?? '') !== 'payment_link.paid') {
            return response()->json(['ok' => true, 'ignored' => $event['event'] ?? null]);
        }

        $link = $event['payload']['payment_link']['entity'] ?? [];
        $payment = $event['payload']['payment']['entity'] ?? [];
        $order = Order::query()->where('payment_link_id', $link['id'] ?? '')->first()
            ?? Order::query()->where('order_number', $link['reference_id'] ?? '')->first();
        if (! $order) {
            return response()->json(['ok' => true, 'ignored' => 'unknown_order']);
        }
        if ((int) ($link['amount_paid'] ?? $payment['amount'] ?? 0) < $order->total_paise) {
            return response()->json(['ok' => true, 'ignored' => 'amount_mismatch']);
        }

        $this->orders->markPaidOnline($order, (string) ($payment['id'] ?? $link['id'] ?? ''), \App\Enums\PaymentMethod::Razorpay);

        return response()->json(['ok' => true, 'order' => $order->order_number]);
    }

    /**
     * WhatsApp Pay: the bot got a "payment" status webhook AND confirmed it with Meta's payment
     * lookup API, then calls this. {reference_id, status: captured, amount_value, transaction_id, pg_transaction_id}
     */
    public function whatsappPayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference_id' => ['required', 'string', 'max:40'],
            'status' => ['required', 'string', 'max:20'],
            'amount_value' => ['required', 'integer', 'min:0'],
            'transaction_id' => ['nullable', 'string', 'max:64'],
            'pg_transaction_id' => ['nullable', 'string', 'max:64'],
        ]);
        $order = Order::query()->where('payment_reference', $data['reference_id'])->first();
        if (! $order) {
            return response()->json(['error' => 'unknown_reference', 'message' => 'No order for this reference.'], 404);
        }
        if ($data['status'] !== 'captured') {
            return response()->json(['ok' => true, 'ignored' => $data['status']]);
        }
        if ($data['amount_value'] < $order->total_paise) {
            return response()->json(['error' => 'amount_mismatch', 'message' => 'Paid amount is lower than the order total.'], 409);
        }
        $order = $this->orders->markPaidOnline($order, $data['pg_transaction_id'] ?: ($data['transaction_id'] ?: 'whatsapp_pay'), \App\Enums\PaymentMethod::WhatsappPay);

        return response()->json(['ok' => true, 'order' => $order->order_number, 'status' => $order->status->value]);
    }

    /** The bot couldn't start the payment (Meta rejected the message, no link): cancel the unpaid order. */
    public function abandonOrder(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['wa_id' => self::WA_ID_RULE, 'reason' => ['nullable', 'string', 'max:200']]);
        $order = Order::query()->where('order_number', $number)
            ->whereHas('customer', fn ($q) => $q->where('wa_id', $data['wa_id']))->firstOrFail();
        $order = $this->orders->expireUnpaid($order, $data['reason'] ?? 'Online payment could not be started', notify: false); // the bot already told the customer

        return response()->json(['ok' => true, 'status' => $order->status->value]);
    }
}
