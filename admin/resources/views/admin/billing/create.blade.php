@use('App\Support\Money')
<x-layouts.admin title="New bill">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">New bill</h1>
            <p class="mt-1 text-sm text-muted">Tap items to add them. The kitchen gets the order as soon as you save.</p>
        </div>
        <a href="{{ route('billing.index') }}" class="btn-ghost">Today’s bills</a>
    </div>

    <form method="post" action="{{ route('billing.store') }}" data-pos data-tax-bp="{{ $taxBp }}" class="grid gap-5 xl:grid-cols-[1fr_380px]">
        @csrf
        <section class="card p-4">
            <label for="pos-search" class="sr-only">Search items</label>
            <input id="pos-search" type="search" placeholder="Search by name or code" class="field mb-4" data-pos-search autocomplete="off">
            @foreach ($categories as $cat)
                <div class="mb-5" data-pos-category>
                    <h2 class="mb-2 text-sm font-semibold text-muted">{{ $cat->name }}</h2>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($cat->items as $item)
                            @php $soldOut = $item->isOutOfStock(); @endphp
                            <button type="button" @disabled($soldOut)
                                    class="flex flex-col items-start rounded-xl border border-line p-3 text-left transition hover:border-brand-500 hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    data-pos-item data-id="{{ $item->id }}" data-name="{{ $item->name }}" data-code="{{ $item->code }}" data-price="{{ $item->price_paise }}">
                                <span class="text-xs text-muted">{{ $item->code }}</span>
                                <span class="text-sm leading-snug font-medium">{{ $item->name }}</span>
                                <span class="mt-1 text-sm">{{ $item->price }}</span>
                                @if ($soldOut)<span class="mt-1 text-xs text-rose-700">Sold out</span>
                                @elseif ($item->isLowStock())<span class="mt-1 text-xs text-amber-700">{{ $item->stock_qty }} left</span>@endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>

        <aside class="card flex flex-col p-4 xl:sticky xl:top-20 xl:self-start">
            <h2 class="font-semibold">Bill</h2>
            <ul class="mt-3 max-h-80 flex-1 divide-y divide-line overflow-y-auto" data-pos-lines>
                <li class="py-6 text-center text-sm text-muted" data-pos-empty>No items yet</li>
            </ul>
            <div data-pos-inputs></div>

            <dl class="mt-3 space-y-1 border-t border-line pt-3 text-sm">
                <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd data-pos-subtotal>₹0</dd></div>
                <div class="flex justify-between"><dt class="text-muted">GST ({{ rtrim(rtrim(number_format($taxBp / 100, 2), '0'), '.') }}%)</dt><dd data-pos-tax>₹0</dd></div>
                <div class="flex justify-between text-base font-semibold"><dt>Total</dt><dd data-pos-total>₹0</dd></div>
            </dl>

            <fieldset class="mt-4 space-y-3 border-t border-line pt-4">
                <legend class="sr-only">Order details</legend>
                <div class="flex gap-2" role="radiogroup" aria-label="Order type">
                    <label class="flex-1"><input type="radio" name="order_type" value="dine_in" class="peer sr-only" @checked(old('order_type', $selectedTable ? 'dine_in' : 'takeaway') === 'dine_in') data-pos-type>
                        <span class="btn-ghost w-full peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700">Dine-in</span></label>
                    <label class="flex-1"><input type="radio" name="order_type" value="takeaway" class="peer sr-only" @checked(old('order_type', $selectedTable ? 'dine_in' : 'takeaway') === 'takeaway') data-pos-type>
                        <span class="btn-ghost w-full peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700">Takeaway</span></label>
                </div>
                <div data-pos-table>
                    <label for="pos-table" class="label">Table</label>
                    <select id="pos-table" name="dining_table_id" class="field">
                        <option value="">Choose a table</option>
                        @foreach ($tables as $t)
                            <option value="{{ $t->id }}" @selected((int) old('dining_table_id', $selectedTable) === $t->id) @disabled($t->openOrder && (int) $selectedTable !== $t->id)>
                                {{ $t->name }} ({{ $t->seats }} seats){{ $t->openOrder ? ' — busy' : '' }}
                            </option>
                        @endforeach
                    </select>
                    @if ($tables->isEmpty())<p class="mt-1 text-xs text-muted">No tables yet. An administrator can add them under Tables.</p>@endif
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div><label for="pos-guest" class="label">Guest name</label><input id="pos-guest" name="guest_name" value="{{ old('guest_name') }}" maxlength="120" class="field" placeholder="Optional"></div>
                    <div><label for="pos-phone" class="label">Phone</label><input id="pos-phone" name="guest_phone" value="{{ old('guest_phone') }}" maxlength="20" inputmode="tel" class="field" placeholder="Optional"></div>
                </div>
                <div><label for="pos-notes" class="label">Kitchen note</label><input id="pos-notes" name="notes" value="{{ old('notes') }}" maxlength="500" class="field" placeholder="e.g. less spicy"></div>
                <div class="flex items-center gap-3">
                    <label for="pos-method" class="label mb-0">Payment</label>
                    <select id="pos-method" name="payment_method" class="field w-auto">
                        @foreach (\App\Enums\PaymentMethod::cases() as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach
                    </select>
                    <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="paid_now" value="1" class="rounded border-line text-brand-600" @checked(old('paid_now'))> Paid now</label>
                </div>
            </fieldset>

            <button class="btn-primary mt-4 w-full py-2.5" data-pos-submit disabled>Save bill &amp; send to kitchen</button>
        </aside>
    </form>
</x-layouts.admin>
