@use('App\Support\Money')
<x-layouts.admin title="Reports">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Reports</h1>
            <p class="mt-1 text-sm text-muted">Cancelled orders are left out of every number.</p>
        </div>
        <form method="get" class="flex flex-wrap items-end gap-2">
            <div><label for="r-from" class="label">From</label><input id="r-from" type="date" name="from" value="{{ $fromDate }}" class="field w-auto"></div>
            <div><label for="r-to" class="label">To</label><input id="r-to" type="date" name="to" value="{{ $toDate }}" class="field w-auto"></div>
            <button class="btn-ghost">Show</button>
            <a href="{{ route('reports.export', ['from' => $fromDate, 'to' => $toDate]) }}" class="btn-primary">Download CSV</a>
        </form>
    </div>

    @php
        $cards = [
            ['Sales (incl. GST)', Money::format($summary['sales']), $summary['orders'].' orders'],
            ['Average order', Money::format($summary['aov']), $summary['cancelled'].' cancelled'],
            ['Expenses', Money::format($summary['expenses']), 'from Expenses page'],
            ['Profit estimate', Money::format($summary['profit']), 'sales without GST − expenses'],
        ];
    @endphp
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as [$label, $value, $hint])
            <div class="card p-5"><p class="text-sm text-muted">{{ $label }}</p><p @class(['mt-1 text-2xl font-semibold', 'text-rose-700' => $label === 'Profit estimate' && $summary['profit'] < 0])>{{ $value }}</p><p class="mt-2 text-xs text-muted">{{ $hint }}</p></div>
        @endforeach
    </section>

    <div class="mt-5 grid gap-5 xl:grid-cols-3">
        <section class="card p-5">
            <h2 class="font-semibold">Money</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-muted">Food (subtotal)</dt><dd>{{ Money::format($summary['subtotal']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">GST collected</dt><dd>{{ Money::format($summary['tax']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Delivery fees</dt><dd>{{ Money::format($summary['delivery']) }}</dd></div>
                <div class="flex justify-between border-t border-line pt-2"><dt class="text-muted">Still unpaid</dt><dd @class(['text-amber-700' => $summary['unpaid']])>{{ Money::format($summary['unpaid']) }}</dd></div>
            </dl>
        </section>
        @foreach (['Where orders came from' => $bySource, 'Order type' => $byType, 'Payment' => $byPayment] as $title => $rows)
            <section class="card p-5">
                <h2 class="font-semibold">{{ $title }}</h2>
                @if ($rows->isEmpty())<p class="mt-3 text-sm text-muted">No orders.</p>@endif
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($rows as $k => $r)
                        <li class="flex justify-between gap-3"><span class="text-muted">{{ $k }} <span class="text-xs">({{ $r['orders'] }})</span></span><span class="font-medium">{{ Money::format($r['sales']) }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <section class="card overflow-hidden">
            <h2 class="border-b border-line px-5 py-4 font-semibold">Day by day</h2>
            <div class="max-h-[420px] overflow-y-auto">
                <table class="w-full">
                    <thead class="sticky top-0 bg-white"><tr><th class="table-head">Day</th><th class="table-head text-right">Orders</th><th class="table-head text-right">Sales</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach (array_reverse($daily, true) as $d)
                            <tr><td class="table-cell">{{ $d['label'] }}</td><td class="table-cell text-right">{{ $d['orders'] }}</td><td class="table-cell text-right font-medium">{{ Money::format($d['sales']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <section class="card overflow-hidden">
            <h2 class="border-b border-line px-5 py-4 font-semibold">Items sold</h2>
            @if ($topItems->isEmpty())
                <p class="px-5 py-8 text-sm text-muted">Nothing sold in this period.</p>
            @else
                <table class="w-full">
                    <thead><tr><th class="table-head">Item</th><th class="table-head text-right">Qty</th><th class="table-head text-right">Revenue</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($topItems as $i)
                            <tr><td class="table-cell">{{ $i->name }}</td><td class="table-cell text-right">{{ (int) $i->qty }}</td><td class="table-cell text-right font-medium">{{ Money::format((int) $i->revenue) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <section class="card p-5">
            <h2 class="font-semibold">By category</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($byCategory as $c)
                    <li class="flex justify-between"><span class="text-muted">{{ $c->name }} <span class="text-xs">({{ (int) $c->qty }})</span></span><span class="font-medium">{{ Money::format((int) $c->revenue) }}</span></li>
                @empty <li class="text-muted">No sales.</li> @endforelse
            </ul>
        </section>
        <section class="card p-5">
            <h2 class="font-semibold">Expenses by category</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($expenseByCategory as $cat => $amt)
                    <li class="flex justify-between"><span class="text-muted">{{ $cat }}</span><span class="font-medium">{{ Money::format($amt) }}</span></li>
                @empty <li class="text-muted">No expenses recorded.</li> @endforelse
            </ul>
        </section>
    </div>
</x-layouts.admin>
