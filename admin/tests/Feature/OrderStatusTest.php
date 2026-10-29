<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Jobs\SendOrderNotification;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\CartException;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderStatusTest extends TestCase
{
    use RefreshDatabase;

    private function order(OrderType $type = OrderType::Delivery): Order
    {
        $customer = Customer::create(['wa_id' => '919876543210', 'name' => 'Test']);
        $order = Order::create([
            'customer_id' => $customer->id, 'status' => OrderStatus::Pending, 'order_type' => $type,
            'subtotal_paise' => 10000, 'total_paise' => 10500, 'idempotency_key' => uniqid(),
        ]);
        $order->update(['order_number' => 'CS'.(1000 + $order->id)]);

        return $order;
    }

    public function test_valid_transition_records_history_and_queues_notification(): void
    {
        Queue::fake();
        $order = $this->order();

        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed);

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(1, $order->histories()->count());
        $this->assertSame(1, $order->notifications()->count());
        Queue::assertPushed(SendOrderNotification::class);
    }

    public function test_invalid_transition_is_refused(): void
    {
        $this->expectException(CartException::class);
        app(OrderService::class)->changeStatus($this->order(), OrderStatus::Completed);
    }

    public function test_takeaway_skips_out_for_delivery(): void
    {
        $this->assertSame(
            [OrderStatus::Completed, OrderStatus::Cancelled],
            OrderStatus::Ready->nextStatuses(OrderType::Takeaway),
        );
    }

    public function test_notification_job_marks_sent_on_success_and_failed_on_4xx(): void
    {
        config(['services.bot.url' => 'http://bot.test', 'services.bot.token' => str_repeat('a', 40)]);
        $order = $this->order();
        $n1 = $order->notifications()->create(['event' => 'order.status_changed', 'status_snapshot' => 'confirmed']);
        $n2 = $order->notifications()->create(['event' => 'order.status_changed', 'status_snapshot' => 'preparing']);

        Http::fakeSequence('bot.test/*')
            ->push(['ok' => true, 'message_id' => 'wamid.X'])
            ->push(['error' => 'outside_24h_window', 'message' => 'Outside window'], 409);

        (new SendOrderNotification($n1->id))->handle();
        (new SendOrderNotification($n2->id))->handle();

        $this->assertSame('sent', $n1->fresh()->state);
        $this->assertSame('wamid.X', $n1->fresh()->wa_message_id);
        $this->assertSame('failed', $n2->fresh()->state);
        $this->assertSame('Outside window', $n2->fresh()->last_error);
    }

    public function test_admin_pages_require_login_and_render(): void
    {
        $this->withoutVite();
        $this->get('/')->assertRedirect('/login');

        $order = $this->order();
        $this->actingAs(User::factory()->create());
        foreach (['/', '/orders', '/orders/'.$order->order_number, '/kitchen', '/menu', '/customers', '/settings', '/menu/items/create'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
