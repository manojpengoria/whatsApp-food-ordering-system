<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Razorpay Payment Links. Keys come from Admin → Settings → Payments.
 * Docs: POST /v1/payment_links, webhook event payment_link.paid (X-Razorpay-Signature = HMAC-SHA256 of raw body).
 */
class RazorpayService
{
    public function __construct(private readonly Settings $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->get('razorpay_key_id') !== '' && $this->settings->secret('razorpay_key_secret') !== '';
    }

    /** Creates (or reuses) a payment link for the order and stores it on the order. */
    public function createPaymentLink(Order $order): string
    {
        if ($order->payment_link_url) {
            return $order->payment_link_url;
        }
        if (! $this->isConfigured()) {
            throw new CartException('payment_not_configured', 'Online payment is not set up yet.', 503);
        }

        $customer = $order->customer;
        $payload = [
            'amount' => $order->total_paise,
            'currency' => 'INR',
            'accept_partial' => false,
            'reference_id' => $order->order_number,
            'description' => $this->settings->get('restaurant_name').' order '.$order->order_number,
            'customer' => array_filter([
                'name' => $customer?->name,
                'contact' => $customer ? '+'.$customer->wa_id : null,
            ]),
            'notify' => ['sms' => false, 'email' => false],  // we send the link on WhatsApp ourselves
            'reminder_enable' => false,
            // Razorpay needs at least ~15 minutes; our own expiry job cancels the order on time.
            'expire_by' => now()->addMinutes(max(16, $this->settings->paymentWindowMinutes() + 1))->timestamp,
            'notes' => ['order_number' => $order->order_number],
        ];

        try {
            $res = $this->http()->post($this->base().'/payment_links', $payload);
        } catch (ConnectionException $e) {
            throw new CartException('payment_unreachable', 'Could not reach Razorpay. Please try again.', 503);
        }
        if (! $res->successful()) {
            throw new CartException('payment_link_failed', 'Razorpay: '.($res->json('error.description') ?? 'HTTP '.$res->status()), 502);
        }

        $order->update(['payment_link_id' => $res->json('id'), 'payment_link_url' => $res->json('short_url')]);

        return (string) $res->json('short_url');
    }

    /** Asks Razorpay whether the order's link is paid. Returns the payment id, or null. */
    public function paidPaymentId(Order $order): ?string
    {
        $res = $this->http()->get($this->base().'/payment_links/'.$order->payment_link_id);
        if (! $res->successful()) {
            throw new \RuntimeException('Razorpay lookup failed: HTTP '.$res->status());
        }
        if ($res->json('status') !== 'paid') {
            return null;
        }

        return (string) (collect($res->json('payments') ?? [])->firstWhere('status', 'captured')['payment_id'] ?? $order->payment_link_id);
    }

    public function cancelLink(Order $order): void
    {
        if ($order->payment_link_id) {
            rescue(fn () => $this->http()->post($this->base().'/payment_links/'.$order->payment_link_id.'/cancel'), report: false);
        }
    }

    /** Admin "Test connection": true if the keys are accepted. */
    public function testConnection(): array
    {
        try {
            $res = $this->http()->timeout(10)->get($this->base().'/payment_links', ['count' => 1]);
        } catch (ConnectionException $e) {
            return [false, 'Could not reach Razorpay: '.$e->getMessage()];
        }
        if ($res->successful()) {
            return [true, str_starts_with((string) $this->settings->get('razorpay_key_id'), 'rzp_test_')
                ? 'Keys work (TEST mode — no real money moves).'
                : 'Keys work (LIVE mode).'];
        }

        return [false, 'Razorpay said: '.($res->json('error.description') ?? 'HTTP '.$res->status())];
    }

    public function validWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = $this->settings->secret('razorpay_webhook_secret');
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    private function http()
    {
        return Http::withBasicAuth((string) $this->settings->get('razorpay_key_id'), $this->settings->secret('razorpay_key_secret'))
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(15);
    }

    private function base(): string
    {
        return rtrim((string) config('services.razorpay.base_url', 'https://api.razorpay.com/v1'), '/');
    }
}
