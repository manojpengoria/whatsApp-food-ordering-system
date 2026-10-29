@use('App\Support\LocalTime')
<x-layouts.admin :title="'Stock · '.$item->name">
    <a href="{{ route('inventory.index') }}" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink"><x-icon name="arrow-left" class="size-4" /> Inventory</a>
    <h1 class="text-2xl font-semibold tracking-tight">{{ $item->name }}</h1>
    <p class="mt-1 mb-5 text-sm text-muted">Now in stock: <span class="font-semibold text-ink">{{ $item->stock_qty }}</span></p>

    <div class="card overflow-hidden">
        @if ($moves->isEmpty())
            <p class="px-6 py-14 text-center text-sm text-muted">No stock changes yet.</p>
        @else
            <table class="w-full">
                <thead class="border-b border-line bg-canvas/60"><tr><th class="table-head">When</th><th class="table-head">What</th><th class="table-head text-right">Change</th><th class="table-head text-right">Stock after</th><th class="table-head">By / note</th></tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($moves as $m)
                        <tr>
                            <td class="table-cell text-muted">{{ LocalTime::format($m->created_at, 'd M, g:i A') }}</td>
                            <td class="table-cell">{{ $m->reasonLabel() }}@if($m->order) · <a class="text-brand-700 hover:underline" href="{{ route('orders.show', $m->order) }}">#{{ $m->order->order_number }}</a>@endif</td>
                            <td @class(['table-cell text-right font-medium', 'text-emerald-700' => $m->change > 0, 'text-rose-700' => $m->change < 0])>{{ $m->change > 0 ? '+' : '' }}{{ $m->change }}</td>
                            <td class="table-cell text-right">{{ $m->balance_after }}</td>
                            <td class="table-cell text-muted">{{ $m->user?->name ?? 'WhatsApp' }}{{ $m->note ? ' — '.$m->note : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $moves->links() }}</div>
</x-layouts.admin>
