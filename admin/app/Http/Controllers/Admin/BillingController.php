<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Services\CartException;
use App\Services\OrderService;
use App\Services\Settings;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** Counter bills for one day. */
    public function index(Request $request): View
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? LocalTime::now()->format('Y-m-d');
        [$from, $to] = LocalTime::dayRangeUtc(CarbonImmutable::createFromFormat('Y-m-d', $date, LocalTime::tz()));

        $bills = Order::query()->where('source', 'pos')->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->with('diningTable')->withSum('items as items_quantity', 'quantity')->latest('id')->get();
        $valid = $bills->where('status.value', '!=', 'cancelled');

        return view('admin.billing.index', [
            'bills' => $bills,
            'date' => $date,
            'total' => $valid->sum('total_paise'),
            'paid' => $valid->where('payment_status', PaymentStatus::Paid)->sum('total_paise'),
            'byMethod' => $valid->where('payment_status', PaymentStatus::Paid)->groupBy(fn ($o) => $o->payment_method->label())->map->sum('total_paise'),
        ]);
    }

    public function create(Request $request, Settings $settings): View
    {
        $categories = MenuCategory::query()->where('is_active', true)->ordered()
            ->with(['items' => fn ($q) => $q->where('is_available', true)])->get()
            ->filter(fn ($c) => $c->items->isNotEmpty());

        $tables = DiningTable::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->with('openOrder')->get();

        return view('admin.billing.create', [
            'categories' => $categories,
            'tables' => $tables,
            'selectedTable' => $request->integer('table') ?: null,
            'taxBp' => $settings->int('tax_rate_bp'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*' => ['integer', 'min:0', 'max:50'],
            'order_type' => ['required', Rule::in([OrderType::DineIn->value, OrderType::Takeaway->value])],
            'dining_table_id' => ['nullable', 'exists:dining_tables,id'],
            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_phone' => ['nullable', 'string', 'max:20'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_now' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $lines = collect($data['lines'])->mapWithKeys(fn ($q, $id) => [(int) $id => (int) $q])->filter()->all();

        try {
            $order = $this->orders->placeCounterOrder(
                $lines,
                OrderType::from($data['order_type']),
                isset($data['dining_table_id']) ? (int) $data['dining_table_id'] : null,
                $data['guest_name'] ?? null,
                $data['guest_phone'] ?? null,
                PaymentMethod::from($data['payment_method']),
                $request->boolean('paid_now'),
                $data['notes'] ?? null,
                $request->user(),
            );
        } catch (CartException $e) {
            return back()->withInput()->withErrors(['bill' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order)
            ->with('status', "Bill {$order->order_number} saved and sent to the kitchen.")
            ->with('print', true);
    }

    /** Printable bill / invoice (works for WhatsApp orders too). */
    public function print(Order $order, Settings $settings): View
    {
        $order->load(['items', 'customer', 'diningTable']);

        return view('admin.billing.print', ['order' => $order, 's' => $settings->all()]);
    }
}
