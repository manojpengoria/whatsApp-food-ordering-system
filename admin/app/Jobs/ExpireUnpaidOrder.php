<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\RazorpayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs when the payment window ends. Before cancelling, it asks the gateway once more
 * whether the order was paid (the webhook could have been missed).
 */
class ExpireUnpaidOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $orderId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(OrderService $orders): void
    {
        $order = Order::query()->find($this->orderId);
        if (! $order || $order->status !== OrderStatus::AwaitingPayment) {
            return;
        }

        $paidRef = $this->lookupPaid($order);
        if ($paidRef !== null) {
            $orders->markPaidOnline($order, $paidRef, $order->payment_method);

            return;
        }
        $orders->expireUnpaid($order);
    }

    /** @return string|null gateway payment id if the order turned out to be paid */
    private function lookupPaid(Order $order): ?string
    {
        try {
            if ($order->payment_method === PaymentMethod::WhatsappPay && $order->payment_reference) {
                $res = Http::withToken((string) config('services.bot.token'))->acceptJson()->timeout(15)
                    ->post(rtrim((string) config('services.bot.url'), '/').'/internal/payment-lookup', [
                        'reference_id' => $order->payment_reference,
                        'configuration_name' => app(\App\Services\Settings::class)->get('wa_pay_config_name'),
                    ]);
                if ($res->successful() && $res->json('status') === 'captured' && (int) $res->json('amount_value') >= $order->total_paise) {
                    return (string) ($res->json('pg_transaction_id') ?: $res->json('transaction_id') ?: 'whatsapp_pay');
                }
            }
            if ($order->payment_method === PaymentMethod::Razorpay && $order->payment_link_id) {
                return app(RazorpayService::class)->paidPaymentId($order);
            }
        } catch (Throwable $e) {
            // If we can't check, try again later rather than cancelling a possibly-paid order.
            Log::warning('Payment lookup before expiry failed', ['order' => $order->order_number, 'error' => $e->getMessage()]);
            throw $e;
        }

        return null;
    }
}
