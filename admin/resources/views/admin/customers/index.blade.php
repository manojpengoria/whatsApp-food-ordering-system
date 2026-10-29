@use('App\Support\LocalTime')
@use('App\Support\Money')
<x-layouts.admin title="Customers">
    <div class="mb-5">
        <h1 class="text-2xl font-semibold tracking-tight">Customers</h1>
        <p class="mt-1 text-sm text-muted">People who have used the WhatsApp ordering bot.</p>
    </div>

    <form method="get" class="mb-4 flex gap-2">
        <label for="cq" class="sr-only">Search customers</label>
        <input id="cq" name="q" value="{{ $q }}" type="search" placeholder="Name or phone" class="field max-w-xs">
        <button class="btn-ghost">Search</button>
    </form>

    <div class="card overflow-hidden">
        @if ($customers->isEmpty())
            <p class="px-6 py-14 text-center text-sm text-muted">{{ $q ? 'No customers match your search.' : 'No customers yet.' }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px]">
                    <thead class="border-b border-line bg-canvas/60">
                        <tr>
                            <th class="table-head">Name</th>
                            <th class="table-head">WhatsApp</th>
                            <th class="table-head text-right">Orders</th>
                            <th class="table-head text-right">Spent</th>
                            <th class="table-head">Last order</th>
                            <th class="table-head">First seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($customers as $c)
                            <tr>
                                <td class="table-cell font-medium">{{ $c->name ?: '—' }}</td>
                                <td class="table-cell"><a href="https://wa.me/{{ $c->wa_id }}" target="_blank" rel="noopener" class="text-brand-700 hover:underline">{{ $c->phone }}</a></td>
                                <td class="table-cell text-right">{{ $c->orders_count }}</td>
                                <td class="table-cell text-right font-medium">{{ Money::format((int) $c->spent_paise) }}</td>
                                <td class="table-cell text-muted">{{ $c->last_order_at ? LocalTime::format(\Carbon\CarbonImmutable::parse($c->last_order_at, 'UTC'), 'd M Y') : '—' }}</td>
                                <td class="table-cell text-muted">{{ LocalTime::format($c->created_at, 'd M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    <div class="mt-4">{{ $customers->links() }}</div>
</x-layouts.admin>
