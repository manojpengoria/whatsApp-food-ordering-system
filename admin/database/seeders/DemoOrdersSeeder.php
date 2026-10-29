<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\CartService;
use App\Support\LocalTime;
use Illuminate\Database\Seeder;

/**
 * OPTIONAL sample orders so the dashboard looks alive in a client demo.
 *   php artisan db:seed --class=DemoOrdersSeeder
 * Uses fake numbers starting 9100000 so they never reach a real person.
 * Remove later with:  php artisan demo:clear
 */
class DemoOrdersSeeder extends Seeder
{
    public const FAKE_PREFIX = '9100000';

    public function run(): void
    {
        $items = MenuItem::query()->where('is_available', true)->get();
        if ($items->isEmpty()) {
            $this->command?->error('Run the MenuSeeder first.');

            return;
        }

        $names = ['Aarav Sharma', 'Priya Mehta', 'Rohan Jain', 'Sneha Agarwal', 'Vikram Singh', 'Ananya Gupta', 'Karan Joshi', 'Neha Rathore', 'Arjun Verma', 'Pooja Saini'];
        $customers = collect($names)->map(fn ($name, $i) => Customer::query()->firstOrCreate(
            ['wa_id' => self::FAKE_PREFIX.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT)],
            ['name' => $name],
        ));

        $carts = app(CartService::class);
        mt_srand(42);
        $now = LocalTime::now();
        $count = 0;

        for ($day = 6; $day >= 0; $day--) {
            $ordersToday = mt_rand(6, 14);
            for ($n = 0; $n < $ordersToday; $n++) {
                $at = $now->subDays($day)->setTime(mt_rand(11, 21), mt_rand(0, 59));
                if ($at->greaterThan($now)) {
                    continue;
                }
                $type = mt_rand(0, 2) ? OrderType::Delivery : OrderType::Takeaway;
                $lines = $items->random(mt_rand(1, 3))->map(fn ($item) => [$item, mt_rand(1, 3)]);
                $subtotal = $lines->sum(fn ($l) => $l[0]->price_paise * $l[1]);
                $totals = $carts->totals($subtotal, $type);

                $status = $day > 0 ? (mt_rand(0, 12) ? OrderStatus::Completed : OrderStatus::Cancelled)
                    : collect([OrderStatus::Completed, OrderStatus::Completed, OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready])->random();

                $order = new Order([
                    'customer_id' => $customers->random()->id,
                    'status' => $status,
                    'order_type' => $type,
                    'source' => 'whatsapp',
                    'delivery_address' => $type === OrderType::Delivery ? 'Demo address, Jaipur' : null,
                    'subtotal_paise' => $subtotal,
                    'tax_rate_bp' => $totals['tax_rate_bp'],
                    'tax_paise' => $totals['tax_paise'],
                    'delivery_fee_paise' => $totals['delivery_fee_paise'],
                    'total_paise' => $totals['total_paise'],
                    'payment_method' => 'cash',
                    'payment_status' => $status === OrderStatus::Completed ? PaymentStatus::Paid : PaymentStatus::Unpaid,
                    'paid_at' => $status === OrderStatus::Completed ? $at->utc() : null,
                    'cancel_reason' => $status === OrderStatus::Cancelled ? 'Demo cancellation' : null,
                    'idempotency_key' => 'demo:'.uniqid('', true),
                ]);
                $order->created_at = $at->utc();
                $order->updated_at = $at->utc();
                $order->save();
                $order->forceFill(['order_number' => config('restaurant.order_prefix').(1000 + $order->id)])->save();

                foreach ($lines as [$item, $qty]) {
                    $order->items()->create([
                        'menu_item_id' => $item->id, 'item_name' => $item->name, 'item_code' => $item->code,
                        'unit_price_paise' => $item->price_paise, 'quantity' => $qty, 'line_total_paise' => $item->price_paise * $qty,
                    ]);
                }
                $order->histories()->create(['to_status' => OrderStatus::Pending, 'note' => 'Placed on WhatsApp (demo)', 'created_at' => $at->utc()]);
                if ($status !== OrderStatus::Pending) {
                    $order->histories()->create(['from_status' => OrderStatus::Pending, 'to_status' => $status, 'created_at' => $at->addMinutes(25)->utc()]);
                }
                $count++;
            }
        }

        $this->command?->info("Created {$count} demo orders for the last 7 days.");
    }
}
