@use('App\Enums\OrderStatus')
@use('App\Support\LocalTime')
<x-layouts.admin title="Orders">
    <div data-refresh-on-order class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Orders</h1>
            <p class="mt-1 text-sm text-muted">Every order placed on WhatsApp.</p>
        </div>
    </div>

    @php
        $tabs = ['all' => ['All', $counts->sum()], 'active' => ['In progress', $activeCount]];
        foreach (OrderStatus::cases() as $s) {
            if ($s !== OrderStatus::AwaitingPayment) { $tabs[$s->value] = [$s->label(), $counts[$s->value] ?? 0]; }
        }
    @endphp
    <nav class="-mx-1 mb-4 flex gap-1 overflow-x-auto pb-1" aria-label="Filter by status">
        @foreach ($tabs as $key => [$label, $count])
            <a href="{{ route('orders.index', array_filter(['status' => $key === 'all' ? null : $key, 'q' => $q ?: null, 'date' => $date ?: null])) }}"
               @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm', 'bg-navy-950 text-white' => $status === $key, 'text-muted hover:bg-white hover:text-ink' => $status !== $key])
               @if($status === $key) aria-current="page" @endif>
                {{ $label }} <span class="ml-0.5 opacity-60">{{ $count }}</span>
            </a>
        @endforeach
    </nav>
    <p class="-mt-2 mb-4 text-xs text-muted">
        Online-payment orders appear here only after they are paid.
        <a href="{{ route('orders.index', ['status' => 'awaiting_payment']) }}" @class(['underline', 'font-medium text-ink' => $status === 'awaiting_payment'])>Waiting for payment ({{ $awaitingCount }})</a>
    </p>

    <form method="get" class="mb-4 flex flex-wrap gap-2">
        @if ($status !== 'all') <input type="hidden" name="status" value="{{ $status }}"> @endif
        <label class="sr-only" for="q">Search</label>
        <input id="q" name="q" value="{{ $q }}" type="search" placeholder="Order number, name or phone" class="field max-w-xs">
        <label class="sr-only" for="date">Date</label>
        <input id="date" name="date" value="{{ $date }}" type="date" class="field w-auto">
        <button class="btn-ghost">Filter</button>
        @if ($q || $date)
            <a href="{{ route('orders.index', array_filter(['status' => $status === 'all' ? null : $status])) }}" class="btn px-2 text-muted hover:text-ink">Clear</a>
        @endif
    </form>

    <div class="card overflow-hidden">
        @if ($orders->isEmpty())
            <p class="px-6 py-14 text-center text-sm text-muted">
                {{ $q || $date || $status !== 'all' ? 'No orders match these filters.' : 'No orders yet. They appear here as soon as a customer orders on WhatsApp.' }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px]">
                    <thead class="border-b border-line bg-canvas/60">
                        <tr>
                            <th class="table-head">Order</th>
                            <th class="table-head">Customer</th>
                            <th class="table-head">Type</th>
                            <th class="table-head text-right">Items</th>
                            <th class="table-head text-right">Total</th>
                            <th class="table-head">Payment</th>
                            <th class="table-head">Status</th>
                            <th class="table-head">Placed</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($orders as $o)
                            <tr class="hover:bg-canvas/50">
                                <td class="table-cell"><a href="{{ route('orders.show', $o) }}" class="font-medium text-brand-700 hover:underline">#{{ $o->order_number }}</a></td>
                                <td class="table-cell">
                                    <p class="font-medium">{{ $o->customer_label }}</p>
                                    <p class="text-xs text-muted">{{ $o->customer_phone ?? $o->source_label }}</p>
                                </td>
                                <td class="table-cell">{{ $o->type_label }}<span class="block text-xs text-muted">{{ $o->source_label }}</span></td>
                                <td class="table-cell text-right">{{ $o->items_count }}</td>
                                <td class="table-cell text-right font-medium">{{ $o->total }}</td>
                                <td class="table-cell">
                                    <span @class(['text-xs font-medium', 'text-emerald-700' => $o->payment_status->value === 'paid', 'text-muted' => $o->payment_status->value !== 'paid'])>
                                        {{ $o->payment_status->label() }} · {{ $o->payment_method->label() }}
                                    </span>
                                </td>
                                <td class="table-cell"><x-status-pill :status="$o->status" /></td>
                                <td class="table-cell text-muted" title="{{ LocalTime::format($o->created_at) }}">{{ LocalTime::format($o->created_at, 'd M, g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
</x-layouts.admin>
