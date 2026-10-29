@use('App\Support\Money')
<x-layouts.admin title="Expenses">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Expenses</h1>
            <p class="mt-1 text-sm text-muted">{{ $monthLabel }} · total {{ Money::format($total) }}</p>
        </div>
        <form method="get" class="flex items-end gap-2">
            <div><label for="e-month" class="label">Month</label><input id="e-month" type="month" name="month" value="{{ $month }}" class="field w-auto"></div>
            <button class="btn-ghost">Show</button>
        </form>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1fr_340px]">
        <div class="card overflow-hidden">
            @if ($expenses->isEmpty())
                <p class="px-6 py-14 text-center text-sm text-muted">No expenses recorded for {{ $monthLabel }}.</p>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($expenses as $e)
                        <li class="px-5 py-3">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="w-16 text-sm text-muted">{{ $e->spent_on->format('d M') }}</span>
                                <div class="min-w-40 flex-1"><p class="text-sm font-medium">{{ $e->description }}</p><p class="text-xs text-muted">{{ $e->category }} · {{ \App\Enums\PaymentMethod::from($e->payment_method)->label() }}@if($e->user) · {{ $e->user->name }}@endif</p></div>
                                <span class="w-24 text-right text-sm font-semibold">{{ $e->amount }}</span>
                                <details>
                                    <summary class="cursor-pointer text-sm text-brand-700">Edit</summary>
                                    <form method="post" action="{{ route('expenses.update', $e) }}" class="mt-3 grid gap-2 sm:grid-cols-2">
                                        @csrf @method('put')
                                        <input type="date" name="spent_on" value="{{ $e->spent_on->format('Y-m-d') }}" required class="field" aria-label="Date">
                                        <select name="category" class="field" aria-label="Category">@foreach ($categories as $c)<option @selected($e->category === $c)>{{ $c }}</option>@endforeach</select>
                                        <input name="description" value="{{ $e->description }}" required maxlength="255" class="field sm:col-span-2" aria-label="Description">
                                        <input name="amount" value="{{ \App\Support\Money::toRupeesString($e->amount_paise) }}" required inputmode="decimal" class="field" aria-label="Amount">
                                        <select name="payment_method" class="field" aria-label="Paid by">@foreach (\App\Enums\PaymentMethod::cases() as $m)<option value="{{ $m->value }}" @selected($e->payment_method === $m->value)>{{ $m->label() }}</option>@endforeach</select>
                                        <button class="btn-primary">Save</button>
                                    </form>
                                    <form method="post" action="{{ route('expenses.destroy', $e) }}" class="mt-2" data-confirm="Delete this expense?">
                                        @csrf @method('delete')
                                        <button class="text-xs text-rose-700 hover:underline">Delete</button>
                                    </form>
                                </details>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <aside class="space-y-5">
            <section class="card p-5">
                <h2 class="font-semibold">Add expense</h2>
                <form method="post" action="{{ route('expenses.store') }}" class="mt-3 space-y-3">
                    @csrf
                    <div class="grid grid-cols-2 gap-2">
                        <div><label for="x-date" class="label">Date</label><input id="x-date" type="date" name="spent_on" value="{{ old('spent_on', \App\Support\LocalTime::now()->format('Y-m-d')) }}" required class="field"></div>
                        <div><label for="x-amount" class="label">Amount (₹)</label><input id="x-amount" name="amount" value="{{ old('amount') }}" required inputmode="decimal" class="field" placeholder="450"></div>
                    </div>
                    <div><label for="x-cat" class="label">Category</label><select id="x-cat" name="category" class="field">@foreach ($categories as $c)<option @selected(old('category') === $c)>{{ $c }}</option>@endforeach</select></div>
                    <div><label for="x-desc" class="label">What for</label><input id="x-desc" name="description" value="{{ old('description') }}" required maxlength="255" class="field" placeholder="e.g. 10 kg paneer"></div>
                    <div><label for="x-pay" class="label">Paid by</label><select id="x-pay" name="payment_method" class="field">@foreach (\App\Enums\PaymentMethod::cases() as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach</select></div>
                    <button class="btn-primary w-full">Add expense</button>
                </form>
            </section>
            @if ($byCategory->isNotEmpty())
                <section class="card p-5">
                    <h2 class="font-semibold">By category</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($byCategory as $cat => $amt)
                            <li class="flex justify-between"><span class="text-muted">{{ $cat }}</span><span class="font-medium">{{ Money::format($amt) }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </aside>
    </div>
</x-layouts.admin>
