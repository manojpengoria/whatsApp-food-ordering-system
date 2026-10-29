@use('App\Support\LocalTime')
@use('App\Support\Money')
<x-layouts.admin title="Billing">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Billing</h1>
            <p class="mt-1 text-sm text-muted">Bills made at the counter or tables. WhatsApp orders are under Orders.</p>
        </div>
        <a href="{{ route('billing.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" /> New bill</a>
    </div>

    <form method="get" class="mb-4 flex items-end gap-2">
        <div><label for="b-date" class="label">Date</label><input id="b-date" type="date" name="date" value="{{ $date }}" class="field w-auto"></div>
        <button class="btn-ghost">Show</button>
    </form>

    <div class="mb-5 grid gap-4 sm:grid-cols-3">
        <div class="card p-4"><p class="text-sm text-muted">Billed</p><p class="mt-1 text-2xl font-semibold">{{ Money::format($total) }}</p></div>
        <div class="card p-4"><p class="text-sm text-muted">Collected</p><p class="mt-1 text-2xl font-semibold">{{ Money::format($paid) }}</p></div>
        <div class="card p-4"><p class="text-sm text-muted">By method</p>
            <p class="mt-1 text-sm">@forelse ($byMethod as $m => $amt) <span class="mr-3"><span class="text-muted">{{ $m }}</span> {{ Money::format($amt) }}</span> @empty — @endforelse</p>
        </div>
    </div>

    <div class="card overflow-hidden">
        @if ($bills->isEmpty())
            <p class="px-6 py-14 text-center text-sm text-muted">No counter bills on this day.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[700px]">
                    <thead class="border-b border-line bg-canvas/60"><tr>
                        <th class="table-head">Bill</th><th class="table-head">Time</th><th class="table-head">Type</th><th class="table-head">Guest</th>
                        <th class="table-head text-right">Items</th><th class="table-head text-right">Total</th><th class="table-head">Payment</th><th class="table-head">Status</th><th class="table-head"></th>
                    </tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($bills as $o)
                            <tr>
                                <td class="table-cell"><a href="{{ route('orders.show', $o) }}" class="font-medium text-brand-700 hover:underline">#{{ $o->order_number }}</a></td>
                                <td class="table-cell text-muted">{{ LocalTime::format($o->created_at, 'g:i A') }}</td>
                                <td class="table-cell">{{ $o->type_label }}</td>
                                <td class="table-cell">{{ $o->customer_label }}</td>
                                <td class="table-cell text-right">{{ $o->items_count }}</td>
                                <td class="table-cell text-right font-medium">{{ $o->total }}</td>
                                <td class="table-cell"><span @class(['text-xs font-medium', 'text-emerald-700' => $o->payment_status->value === 'paid', 'text-amber-700' => $o->payment_status->value !== 'paid'])>{{ $o->payment_status->label() }} · {{ $o->payment_method->label() }}</span></td>
                                <td class="table-cell"><x-status-pill :status="$o->status" /></td>
                                <td class="table-cell"><a href="{{ route('billing.print', $o) }}" target="_blank" class="text-sm text-brand-700 hover:underline">Print</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.admin>
