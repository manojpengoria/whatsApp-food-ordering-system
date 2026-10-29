<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\LocalTime;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $today = LocalTime::now();
        [$todayStart, $todayEnd] = LocalTime::dayRangeUtc($today);
        [$yStart, $yEnd] = LocalTime::dayRangeUtc($today->subDay());

        $t = $this->dayStats($todayStart, $todayEnd);
        $y = $this->dayStats($yStart, $yEnd);

        $kpis = [
            ['label' => 'Total sales', 'value' => $t['sales'], 'prev' => $y['sales'], 'money' => true, 'icon' => 'trend'],
            ['label' => 'Orders', 'value' => $t['orders'], 'prev' => $y['orders'], 'money' => false, 'icon' => 'receipt'],
            ['label' => 'Average order value', 'value' => $t['aov'], 'prev' => $y['aov'], 'money' => true, 'icon' => 'bag'],
            ['label' => 'New customers', 'value' => $t['new_customers'], 'prev' => $y['new_customers'], 'money' => false, 'icon' => 'people'],
        ];

        return view('admin.dashboard', [
            'today' => $today,
            'kpis' => $kpis,
            'week' => $this->lastSevenDays($today),
            'topItems' => $this->topItems($today),
            'activeOrders' => Order::query()->active()->with(['customer', 'diningTable'])->withSum('items as items_quantity', 'quantity')
                ->oldest('id')->limit(4)->get(),
            'activeCount' => Order::query()->active()->count(),
            'recentOrders' => Order::query()->visible()->with(['customer', 'diningTable'])->latest('id')->limit(5)->get(),
        ]);
    }

    /** Sales and orders exclude cancelled orders. */
    private function dayStats($from, $to): array
    {
        $row = Order::query()->notCancelled()->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total_paise),0) as s')->first();

        $orders = (int) $row->c;
        $sales = (int) $row->s;

        // A "new customer" placed their very first order on this day.
        $newCustomers = DB::table('orders')
            ->whereNotIn('status', OrderStatus::hiddenValues())
            ->groupBy('customer_id')
            ->havingRaw('MIN(created_at) >= ? AND MIN(created_at) < ?', [$from, $to])
            ->select('customer_id')
            ->get()->count();

        return [
            'orders' => $orders,
            'sales' => $sales,
            'aov' => $orders ? intdiv($sales, $orders) : 0,
            'new_customers' => $newCustomers,
        ];
    }

    private function lastSevenDays($today): array
    {
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $today->subDays($i);
            [$from, $to] = LocalTime::dayRangeUtc($day);
            $days[] = [
                'label' => $day->format('D'),
                'date' => $day->format('d M'),
                'sales' => (int) Order::query()->notCancelled()->where('created_at', '>=', $from)->where('created_at', '<', $to)->sum('total_paise'),
            ];
        }

        return $days;
    }

    private function topItems($today)
    {
        [$from] = LocalTime::dayRangeUtc($today->subDays(29));

        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', OrderStatus::hiddenValues())
            ->where('orders.created_at', '>=', $from)
            ->groupBy('order_items.item_name')
            ->selectRaw('order_items.item_name as name, SUM(order_items.quantity) as qty, SUM(order_items.line_total_paise) as revenue')
            ->orderByDesc('qty')
            ->limit(5)
            ->get();
    }
}
