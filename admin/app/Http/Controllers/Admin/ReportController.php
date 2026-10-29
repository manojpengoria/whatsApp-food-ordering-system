<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        [$fromDate, $toDate, $from, $to] = $this->range($request);

        $orders = Order::query()->visible()->where('created_at', '>=', $from)->where('created_at', '<', $to)->get();
        $valid = $orders->where('status', '!=', OrderStatus::Cancelled);

        // Daily rows in restaurant time
        $daily = [];
        for ($d = $fromDate; $d->lte($toDate); $d = $d->addDay()) {
            $daily[$d->format('Y-m-d')] = ['label' => $d->format('d M, D'), 'orders' => 0, 'sales' => 0];
        }
        foreach ($valid as $o) {
            $key = LocalTime::of($o->created_at)->format('Y-m-d');
            if (isset($daily[$key])) {
                $daily[$key]['orders']++;
                $daily[$key]['sales'] += $o->total_paise;
            }
        }

        $itemBase = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotIn('orders.status', OrderStatus::hiddenValues())
            ->where('orders.created_at', '>=', $from)->where('orders.created_at', '<', $to);

        $topItems = (clone $itemBase)->groupBy('order_items.item_name')
            ->selectRaw('order_items.item_name as name, SUM(order_items.quantity) as qty, SUM(order_items.line_total_paise) as revenue')
            ->orderByDesc('revenue')->limit(15)->get();

        $byCategory = (clone $itemBase)
            ->leftJoin('menu_items', 'menu_items.id', '=', 'order_items.menu_item_id')
            ->leftJoin('menu_categories', 'menu_categories.id', '=', 'menu_items.menu_category_id')
            ->groupBy('menu_categories.name')
            ->selectRaw("COALESCE(menu_categories.name, 'Deleted items') as name, SUM(order_items.quantity) as qty, SUM(order_items.line_total_paise) as revenue")
            ->orderByDesc('revenue')->get();

        $expenses = Expense::query()->whereDate('spent_on', '>=', $fromDate->toDateString())->whereDate('spent_on', '<=', $toDate->toDateString())->get();

        $sales = $valid->sum('total_paise');
        $netSales = $valid->sum('subtotal_paise') + $valid->sum('delivery_fee_paise'); // without GST

        return view('admin.reports.index', [
            'fromDate' => $fromDate->format('Y-m-d'),
            'toDate' => $toDate->format('Y-m-d'),
            'summary' => [
                'orders' => $valid->count(),
                'cancelled' => $orders->count() - $valid->count(),
                'sales' => $sales,
                'subtotal' => $valid->sum('subtotal_paise'),
                'tax' => $valid->sum('tax_paise'),
                'delivery' => $valid->sum('delivery_fee_paise'),
                'aov' => $valid->count() ? intdiv($sales, $valid->count()) : 0,
                'unpaid' => $valid->where('payment_status.value', 'unpaid')->sum('total_paise'),
                'expenses' => $expenses->sum('amount_paise'),
                'profit' => $netSales - $expenses->sum('amount_paise'),
            ],
            'bySource' => $valid->groupBy(fn ($o) => $o->source === 'pos' ? 'Counter' : 'WhatsApp')->map(fn ($g) => ['orders' => $g->count(), 'sales' => $g->sum('total_paise')]),
            'byType' => $valid->groupBy(fn ($o) => $o->order_type->label())->map(fn ($g) => ['orders' => $g->count(), 'sales' => $g->sum('total_paise')]),
            'byPayment' => $valid->groupBy(fn ($o) => $o->payment_method->label().' · '.$o->payment_status->label())->map(fn ($g) => ['orders' => $g->count(), 'sales' => $g->sum('total_paise')]),
            'daily' => $daily,
            'topItems' => $topItems,
            'byCategory' => $byCategory,
            'expenseByCategory' => $expenses->groupBy('category')->map->sum('amount_paise')->sortDesc(),
        ]);
    }

    /** CSV of every order in the range (opens in Excel / Google Sheets). */
    public function export(Request $request): StreamedResponse
    {
        [$fromDate, $toDate, $from, $to] = $this->range($request);
        $name = 'orders_'.$fromDate->format('Ymd').'_'.$toDate->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($from, $to) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₹ and Hindi names correctly
            fputcsv($out, ['Order', 'Date', 'Time', 'Source', 'Type', 'Table', 'Customer', 'Phone', 'Items', 'Subtotal', 'GST', 'Delivery', 'Total', 'Payment', 'Payment status', 'Status']);

            Order::query()->visible()->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->with(['customer', 'diningTable', 'items'])->orderBy('id')
                ->chunk(200, function ($orders) use ($out) {
                    foreach ($orders as $o) {
                        $at = LocalTime::of($o->created_at);
                        fputcsv($out, [
                            $o->order_number, $at->format('Y-m-d'), $at->format('H:i'), $o->source_label,
                            $o->order_type->label(), $o->diningTable?->name, $o->customer_label, $o->customer_phone,
                            $o->items->map(fn ($i) => $i->quantity.' x '.$i->item_name)->implode('; '),
                            Money::toRupeesString($o->subtotal_paise), Money::toRupeesString($o->tax_paise),
                            Money::toRupeesString($o->delivery_fee_paise), Money::toRupeesString($o->total_paise),
                            $o->payment_method->label(), $o->payment_status->label(), $o->status->label(),
                        ]);
                    }
                });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable} */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $now = LocalTime::now();
        $fromDate = isset($data['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'], LocalTime::tz())->startOfDay() : $now->startOfMonth();
        $toDate = isset($data['to']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['to'], LocalTime::tz())->startOfDay() : $now->startOfDay();
        if ($fromDate->diffInDays($toDate) > 366) {
            $fromDate = $toDate->subDays(366);
        }
        [$from] = LocalTime::dayRangeUtc($fromDate);
        [, $to] = LocalTime::dayRangeUtc($toDate);

        return [$fromDate, $toDate, $from, $to];
    }
}
