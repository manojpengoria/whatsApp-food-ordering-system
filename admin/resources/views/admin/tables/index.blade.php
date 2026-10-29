@use('App\Support\LocalTime')
<x-layouts.admin title="Tables" :auto-refresh="30">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Tables</h1>
            <p class="mt-1 text-sm text-muted">{{ $occupied }} of {{ $tables->where('is_active', true)->count() }} tables busy. Tap a free table to start a bill.</p>
        </div>
        <a href="{{ route('billing.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" /> New bill</a>
    </div>

    @if ($tables->isEmpty())
        <p class="card px-6 py-14 text-center text-sm text-muted">
            No tables yet.@if(auth()->user()->isAdmin()) Add your first table below.@else Ask an administrator to add tables.@endif
        </p>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($tables->where('is_active', true) as $t)
                @php $o = $t->openOrder; @endphp
                <article @class(['card p-4', 'border-amber-300 bg-amber-50/40' => $o])>
                    <div class="flex items-start justify-between">
                        <div>
                            <h2 class="text-lg font-semibold">{{ $t->name }}</h2>
                            <p class="text-xs text-muted">{{ $t->seats }} seats</p>
                        </div>
                        <span @class(['pill', 'bg-amber-100 text-amber-800 ring-amber-200' => $o, 'bg-emerald-50 text-emerald-700 ring-emerald-200' => ! $o])>
                            {{ $o ? 'Occupied' : 'Free' }}
                        </span>
                    </div>
                    @if ($o)
                        <div class="mt-3 space-y-1 text-sm">
                            <p><a href="{{ route('orders.show', $o) }}" class="font-medium text-brand-700 hover:underline">#{{ $o->order_number }}</a> · {{ $o->total }}</p>
                            <p class="text-muted">{{ $o->items->sum('quantity') }} items · since {{ LocalTime::format($o->created_at, 'g:i A') }}</p>
                            <x-status-pill :status="$o->status" />
                        </div>
                        <div class="mt-4 flex gap-2">
                            <a href="{{ route('orders.show', $o) }}" class="btn-ghost flex-1 py-1.5">Open</a>
                            <a href="{{ route('billing.print', $o) }}" target="_blank" class="btn-ghost flex-1 py-1.5">Print</a>
                        </div>
                    @else
                        <a href="{{ route('billing.create', ['table' => $t->id]) }}" class="btn-primary mt-6 w-full py-1.5">Start bill</a>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    @if (auth()->user()->isAdmin())
        <section class="card mt-8 p-5">
            <h2 class="font-semibold">Manage tables</h2>
            <form method="post" action="{{ route('tables.store') }}" class="mt-3 flex flex-wrap items-end gap-2">
                @csrf
                <div><label for="t-name" class="label">Name</label><input id="t-name" name="name" required maxlength="40" class="field w-40" placeholder="T1"></div>
                <div><label for="t-seats" class="label">Seats</label><input id="t-seats" name="seats" type="number" min="1" max="30" value="4" required class="field w-24"></div>
                <button class="btn-primary">Add table</button>
            </form>

            @if ($tables->isNotEmpty())
                <ul class="mt-5 divide-y divide-line border-t border-line">
                    @foreach ($tables as $t)
                        <li class="flex flex-wrap items-center gap-2 py-2">
                            <form method="post" action="{{ route('tables.update', $t) }}" class="flex flex-1 flex-wrap items-center gap-2">
                                @csrf @method('put')
                                <input name="name" value="{{ $t->name }}" required maxlength="40" class="field w-36" aria-label="Table name">
                                <label class="flex items-center gap-1 text-xs text-muted">Seats <input name="seats" type="number" min="1" max="30" value="{{ $t->seats }}" class="field w-20"></label>
                                <label class="flex items-center gap-1 text-xs text-muted">Order <input name="sort_order" type="number" min="0" max="999" value="{{ $t->sort_order }}" class="field w-20"></label>
                                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="is_active" value="1" @checked($t->is_active) class="rounded border-line text-brand-600"> In use</label>
                                <button class="btn-ghost py-1.5">Save</button>
                            </form>
                            <form method="post" action="{{ route('tables.destroy', $t) }}" data-confirm="Delete table {{ $t->name }}?">
                                @csrf @method('delete')
                                <button class="text-xs text-rose-700 hover:underline">Delete</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</x-layouts.admin>
