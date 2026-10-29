<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Models\Order;

/**
 * Builds the "parameters" of a WhatsApp Pay order_details message (Review and Pay).
 * Amounts come from the saved order, so WhatsApp shows exactly what we charge.
 * Meta docs: total = subtotal + tax + shipping; value = rupees × 100 (offset 100).
 */
class WhatsappPayService
{
    public function __construct(private readonly Settings $settings) {}

    public function parameters(Order $order): array
    {
        $order->loadMissing(['items.menuItem', 'customer']);
        $money = fn (int $paise) => ['value' => $paise, 'offset' => 100];

        $items = $order->items->map(function ($i) use ($money) {
            $row = [
                'name' => mb_substr($i->item_name, 0, 60),
                'amount' => $money($i->unit_price_paise),
                'quantity' => $i->quantity,
            ];
            if ($i->menuItem?->catalog_synced_at) {
                $row['retailer_id'] = $i->menuItem->retailer_id;
            }

            return $row;
        })->values()->all();

        $orderObj = [
            'status' => 'pending',
            'catalog_id' => (string) config('restaurant.catalog.id'),
            'expiration' => [
                'timestamp' => (string) now()->addMinutes($this->settings->paymentWindowMinutes())->timestamp,
                'description' => 'Unpaid orders are cancelled after '.$this->settings->paymentWindowMinutes().' minutes.',
            ],
            'items' => $items,
            'subtotal' => $money($order->subtotal_paise),
            'tax' => $money($order->tax_paise) + ['description' => 'GST'],
        ];
        if ($order->delivery_fee_paise > 0) {
            $orderObj['shipping'] = $money($order->delivery_fee_paise) + ['description' => 'Delivery'];
        }

        $params = [
            'reference_id' => $order->payment_reference,
            'type' => 'physical-goods',
            'payment_settings' => [[
                'type' => 'payment_gateway',
                'payment_gateway' => array_filter([
                    'type' => $this->settings->get('wa_pay_gateway'),
                    'configuration_name' => $this->settings->get('wa_pay_config_name'),
                    'razorpay' => $this->settings->get('wa_pay_gateway') === 'razorpay'
                        ? ['receipt' => $order->order_number, 'notes' => ['order_number' => $order->order_number]]
                        : null,
                ]),
            ]],
            'currency' => 'INR',
            'total_amount' => $money($order->total_paise),
            'order' => $orderObj,
        ];

        if ($order->order_type === OrderType::Delivery) {
            $params['beneficiaries'] = [$this->beneficiary($order)];
        }

        return $params;
    }

    /** Meta needs a structured address for delivered goods (not shown to the customer). */
    private function beneficiary(Order $order): array
    {
        $address = trim((string) $order->delivery_address);
        preg_match('/\b(\d{6})\b/', $address, $pin);

        return [
            'name' => mb_substr($order->customer?->name ?: 'Customer', 0, 200),
            'address_line1' => mb_substr($address, 0, 100),
            'address_line2' => mb_strlen($address) > 100 ? mb_substr($address, 100, 100) : null,
            'city' => $this->settings->get('restaurant_city') ?: 'Jaipur',
            'state' => $this->settings->get('restaurant_state') ?: 'Rajasthan',
            'country' => 'India',
            'postal_code' => $pin[1] ?? ($this->settings->get('restaurant_pincode') ?: '302001'),
        ];
    }
}
