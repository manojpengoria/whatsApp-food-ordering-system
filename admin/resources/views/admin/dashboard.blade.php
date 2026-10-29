@use('App\Support\Money')
@use('App\Support\LocalTime')
@use('App\Support\SvgChart')
<x-layouts.admin title="Dashboard">
    <div data-refresh-on-order class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Welcome back, {{ strtok(auth()->user()->name, ' ') }}</h1>
            <p class="mt-1 text-sm text-muted">Here’s how today is going on WhatsApp.</p>
        </div>
        <span class="inline-flex items-center gap-2 rounded-lg border border-line bg-white px-3 py-2 text-sm">
            <x-icon name="calendar" class="size-4 text-muted" /> Today, {{ $today->format('d M Y') }}
        </span>
    </div>

    {{-- KPI cards --}}
    @php
        $iconTint = ['trend' => 'bg-sky-50 text-sky-600', 'receipt' => 'bg-emerald-50 text-emerald-600', 'bag' => 'bg-violet-50 text-violet-600', 'people' => 'bg-amber-50 text-amber-600'];
    @endphp
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Today’s numbers">
        @foreach ($kpis as $kpi)
            @php
                $delta = $kpi['prev'] > 0 ? round(($kpi['value'] - $kpi['prev']) / $kpi['prev'] * 100, 1) : null;
            @endphp
            <div class="card p-5">
                <div class="flex items-start justify-between">
                    <p class="text-sm text-muted">{{ $kpi['label'] }}</p>
                    <span class="grid size-10 place-items-center rounded-full {{ $iconTint[$kpi['icon']] }}"><x-icon :name="$kpi['icon']" class="size-5" /></span>
                </div>
                <p class="mt-1 text-[28px] leading-none font-semibold tracking-tight">
                    {{ $kpi['money'] ? Money::format($kpi['value']) : number_format($kpi['value']) }}
                </p>
                <div class="mt-4 flex items-center justify-between text-xs">
                    <span class="text-muted">vs yesterday</span>
                    @if ($delta === null)
                        <span class="text-muted">{{ $kpi['value'] > 0 ? 'New' : '—' }}</span>
                    @else
                        <span @class(['inline-flex items-center gap-0.5 font-medium', 'text-emerald-600' => $delta >= 0, 'text-rose-600' => $delta < 0])>
                            <x-icon :name="$delta >= 0 ? 'up' : 'down'" class="size-3.5" />{{ abs($delta) }}%
                        </span>
                    @endif
                </div>
            </div>
        @endforeach
    </section>

    <div class="mt-5 grid gap-5 xl:grid-cols-5">
        {{-- Sales chart --}}
        <section class="card p-5 xl:col-span-3">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Sales overview</h2>
                <span class="rounded-lg border border-line px-2.5 py-1 text-xs text-muted">Last 7 days</span>
            </div>
            @php
                $chart = SvgChart::build(array_column($week, 'sales'), 600, 200, 0, 0);
                $weekTotal = array_sum(array_column($week, 'sales'));
            @endphp
            <p class="mt-1 text-sm text-muted">{{ Money::format($weekTotal) }} this week</p>
            <div class="mt-4 flex gap-3">
                <div class="relative h-[200px] w-14 shrink-0 text-right text-[11px] text-muted" aria-hidden="true">
                    @foreach ($chart['ticks'] as $i => $tick)
                        <span class="absolute right-0 -translate-y-1/2" style="top: {{ $i * 25 }}%">{{ $tick >= 100000 ? '₹'.rtrim(rtrim(number_format($tick / 100000, 1), '0'), '.').'k' : Money::format($tick) }}</span>
                    @endforeach
                </div>
                <div class="min-w-0 flex-1">
                    <svg viewBox="0 0 600 200" class="h-[200px] w-full overflow-visible" preserveAspectRatio="none" role="img"
                         aria-label="Daily sales for the last 7 days">
                        <defs>
                            <linearGradient id="salesFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="var(--color-brand-500)" stop-opacity="0.28" />
                                <stop offset="100%" stop-color="var(--color-brand-500)" stop-opacity="0.02" />
                            </linearGradient>
                        </defs>
                        @foreach ([0, 50, 100, 150] as $gy)
                            <line x1="0" x2="600" y1="{{ $gy }}" y2="{{ $gy }}" stroke="var(--color-line)" stroke-dasharray="3 4" vector-effect="non-scaling-stroke" />
                        @endforeach
                        <line x1="0" x2="600" y1="200" y2="200" stroke="var(--color-line)" vector-effect="non-scaling-stroke" />
                        <path d="{{ $chart['area'] }}" fill="url(#salesFill)" />
                        <path d="{{ $chart['line'] }}" fill="none" stroke="var(--color-brand-600)" stroke-width="2.5" vector-effect="non-scaling-stroke" />
                    </svg>
                    <div class="relative -mt-[200px] h-[200px]" aria-hidden="true">
                        @foreach ($chart['points'] as $i => $p)
                            <span class="group absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-brand-600 shadow"
                                  style="left: {{ $p['x'] / 6 }}%; top: {{ $p['y'] / 2 }}%">
                                <span class="pointer-events-none absolute bottom-4 left-1/2 hidden -translate-x-1/2 rounded-md bg-navy-950 px-2 py-1 text-xs whitespace-nowrap text-white group-hover:block">
                                    {{ $week[$i]['date'] }}: {{ Money::format($week[$i]['sales']) }}
                                </span>
                            </span>
                        @endforeach
                    </div>
                    <div class="mt-2 flex justify-between text-xs text-muted">
                        @foreach ($week as $d)
                            <span>{{ $d['label'] }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        {{-- Top items --}}
        <section class="card p-5 xl:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Top selling items</h2>
                <a href="{{ route('menu.index') }}" class="text-sm font-medium text-brand-700 hover:underline">View menu</a>
            </div>
            <p class="mt-1 text-sm text-muted">Last 30 days</p>
            @if ($topItems->isEmpty())
                <p class="mt-8 rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm text-muted">
                    No orders yet. Send “hi” to the WhatsApp number to place a test order.
                </p>
            @else
                @php $palette = ['bg-amber-100 text-amber-800', 'bg-rose-100 text-rose-800', 'bg-emerald-100 text-emerald-800', 'bg-sky-100 text-sky-800', 'bg-violet-100 text-violet-800']; @endphp
                <ul class="mt-4 divide-y divide-line">
                    @foreach ($topItems as $i => $item)
                        <li class="flex items-center gap-3 py-3">
                            <span class="grid size-9 shrink-0 place-items-center rounded-lg text-sm font-semibold {{ $palette[$i % 5] }}">{{ mb_substr($item->name, 0, 1) }}</span>
                            <span class="min-w-0 flex-1 truncate text-sm">{{ $item->name }}</span>
                            <span class="w-12 text-right text-sm text-muted">{{ (int) $item->qty }}</span>
                            <span class="w-20 text-right text-sm font-medium">{{ Money::format((int) $item->revenue) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-5">
        {{-- Active orders --}}
        <section class="card p-5 xl:col-span-3">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Active orders <span class="ml-1 text-sm font-normal text-muted">{{ $activeCount }}</span></h2>
                <a href="{{ route('kitchen') }}" class="text-sm font-medium text-brand-700 hover:underline">Open kitchen board</a>
            </div>
            @if ($activeOrders->isEmpty())
                <p class="mt-4 rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm text-muted">No orders in progress right now.</p>
            @else
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($activeOrders as $o)
                        <a href="{{ route('orders.show', $o) }}" class="rounded-xl border border-line p-3.5 hover:border-brand-500">
                            <p class="font-semibold">#{{ $o->order_number }}</p>
                            <p class="mt-0.5 truncate text-sm text-muted">{{ $o->type_label }} · {{ $o->customer_label }}</p>
                            <p class="text-sm text-muted">{{ $o->items_count }} {{ Str::plural('item', $o->items_count) }}</p>
                            <x-status-pill :status="$o->status" class="mt-3" />
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Recent orders --}}
        <section class="card p-5 xl:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Recent orders</h2>
                <a href="{{ route('orders.index') }}" class="text-sm font-medium text-brand-700 hover:underline">View all</a>
            </div>
            @if ($recentOrders->isEmpty())
                <p class="mt-4 rounded-xl border border-dashed border-line px-4 py-8 text-center text-sm text-muted">Orders placed on WhatsApp will show up here.</p>
            @else
                <ul class="mt-3 divide-y divide-line">
                    @foreach ($recentOrders as $o)
                        <li>
                            <a href="{{ route('orders.show', $o) }}" class="flex items-center gap-3 py-3 text-sm hover:text-brand-700">
                                <span class="w-24 font-medium">#{{ $o->order_number }}</span>
                                <span class="flex-1 truncate text-muted">{{ $o->type_label }}</span>
                                <span class="w-20 text-right font-medium">{{ $o->total }}</span>
                                <span @class(['pill w-16 justify-center', 'bg-emerald-50 text-emerald-700 ring-emerald-200' => $o->payment_status->value === 'paid', 'bg-slate-50 text-slate-600 ring-slate-200' => $o->payment_status->value !== 'paid'])>
                                    {{ $o->payment_status->label() }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-layouts.admin>
