<?php

namespace App\Jobs;

use App\Models\OrderNotification;
use App\Support\Phone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Asks the Node bot (POST /internal/notify) to send an order update on WhatsApp.
 * Node de-duplicates on notification_id, so retries never send the same update twice.
 */
class SendOrderNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public int $notificationId) {}

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(): void
    {
        $n = OrderNotification::query()->with('order.customer', 'order.items')->find($this->notificationId);
        if (! $n || $n->state === 'sent') {
            return;
        }
        $order = $n->order;
        if (! $order->customer) {
            $n->update(['state' => 'failed', 'last_error' => 'Counter order has no WhatsApp customer.']);

            return;
        }
        $n->increment('attempts');

        $baseUrl = rtrim((string) config('services.bot.url'), '/');
        $token = (string) config('services.bot.token');
        if ($baseUrl === '' || $token === '') {
            $this->markFailed($n, 'BOT_URL or WM_INTERNAL_TOKEN is not set in admin/.env');

            return;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->post($baseUrl.'/internal/notify', [
                    'notification_id' => $n->id,
                    'event' => $n->event,
                    'wa_id' => $order->customer->wa_id,
                    'customer_name' => $order->customer->name,
                    'order' => [
                        'number' => $order->order_number,
                        'status' => $n->status_snapshot,
                        'order_type' => $order->order_type->value,
                        'total' => $order->total,
                        'cancel_reason' => $order->cancel_reason,
                        'payment_method' => $order->payment_method->value,
                        'payment_ref' => $order->payment_ref,
                        'payment_reference' => $order->payment_reference,
                        'payment_status' => $order->payment_status->value,
                        'payment_window_minutes' => app(\App\Services\Settings::class)->paymentWindowMinutes(),
                    ],
                ]);
        } catch (ConnectionException $e) {
            $this->retryOrFail($n, 'Bot not reachable: '.$e->getMessage());

            return;
        }

        if ($response->successful()) {
            $n->update([
                'state' => 'sent',
                'sent_at' => now(),
                'last_error' => null,
                'wa_message_id' => $response->json('message_id'),
            ]);
            Log::info('Order notification sent', ['order' => $order->order_number, 'to' => Phone::mask($order->customer->wa_id)]);

            return;
        }

        $error = $response->json('error') ?? ('HTTP '.$response->status());
        $message = $response->json('message') ?? $error;

        // 4xx = permanent (bad token, outside 24h window, invalid number). Don't retry.
        if ($response->clientError()) {
            $this->markFailed($n, $message);

            return;
        }

        $this->retryOrFail($n, $message);
    }

    private function retryOrFail(OrderNotification $n, string $error): void
    {
        $n->update(['last_error' => mb_substr($error, 0, 500)]);
        // With QUEUE_CONNECTION=sync there is no worker to retry later, so fail right away
        // instead of throwing an error into the admin's request.
        if ($this->attempts() >= $this->tries || config('queue.default') === 'sync') {
            $this->markFailed($n, $error);

            return;
        }
        // Throwing makes the queue retry with backoff().
        throw new RuntimeException($error);
    }

    private function markFailed(OrderNotification $n, string $error): void
    {
        $n->update(['state' => 'failed', 'last_error' => mb_substr($error, 0, 500)]);
        Log::warning('Order notification failed', ['notification' => $n->id, 'error' => $error]);
    }

    public function failed(?\Throwable $e): void
    {
        OrderNotification::query()->whereKey($this->notificationId)->where('state', '!=', 'sent')
            ->update(['state' => 'failed', 'last_error' => mb_substr((string) $e?->getMessage(), 0, 500)]);
    }
}
