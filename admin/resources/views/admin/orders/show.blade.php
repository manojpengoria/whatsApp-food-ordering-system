@use('App\Enums\OrderStatus')
@use('App\Support\LocalTime')
@use('App\Support\Money')
<x-layouts.admin :title="'Order '.$order->order_number">
    <a href="{{ route('orders.index') }}" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink">
        <x-icon name="arrow-left" class="size-4" /> All orders
    </a>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold tracking-tight">Order #{{ $order->order_number }}</h1>
                <x-status-pill :status="$order->status" />
            </div>
            <p class="mt-1 text-sm text-muted">
                {{ $order->type_label }} · {{ $order->source_label }} · placed {{ LocalTime::format($order->created_at) }} ({{ LocalTime::ago($order->created_at) }})@if($order->creator) · by {{ $order->creator->name }}@endif
            </p>
        </div>

        <div class="flex flex-wrap items-start gap-2">
            <a href="{{ route('billing.print', $order) }}" target="_blank" class="btn-ghost">Print bill</a>
        @if ($next)
                @foreach ($next as $to)
                    @continue($to === OrderStatus::Cancelled)
                    <form method="post" action="{{ route('orders.status', $order) }}">
                        @csrf
                        <input type="hidden" name="status" value="{{ $to->value }}">
                        <button class="btn-primary">{{ $to->actionLabel($order->order_type) }}</button>
                    </form>
                @endforeach
        @endif
        </div>
    </div>
    @if (session('print'))
        <script>window.addEventListener('load', () => window.open(@json(route('billing.print', $order)), '_blank'));</script>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card">
                <h2 class="border-b border-line px-5 py-4 font-semibold">Items</h2>
                <ul class="divide-y divide-line">
                    @foreach ($order->items as $item)
                        <li class="flex items-center gap-4 px-5 py-3 text-sm">
                            <span class="w-10 text-muted">{{ $item->quantity }}×</span>
                            <span class="flex-1">{{ $item->item_name }} @if($item->item_code)<span class="text-xs text-muted">({{ $item->item_code }})</span>@endif</span>
                            <span class="text-muted">{{ Money::format($item->unit_price_paise) }}</span>
                            <span class="w-24 text-right font-medium">{{ Money::format($item->line_total_paise) }}</span>
                        </li>
                    @endforeach
                </ul>
                <dl class="space-y-1.5 border-t border-line px-5 py-4 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd>{{ Money::format($order->subtotal_paise) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">GST ({{ rtrim(rtrim(number_format($order->tax_rate_bp / 100, 2), '0'), '.') }}%)</dt><dd>{{ Money::format($order->tax_paise) }}</dd></div>
                    @if ($order->delivery_fee_paise)
                        <div class="flex justify-between"><dt class="text-muted">Delivery fee</dt><dd>{{ Money::format($order->delivery_fee_paise) }}</dd></div>
                    @endif
                    <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Total</dt><dd>{{ $order->total }}</dd></div>
                </dl>
            </section>

            @if ($order->customer)
            <section class="card">
                <h2 class="border-b border-line px-5 py-4 font-semibold">WhatsApp chat</h2>
                @if ($messages->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-muted">No messages recorded for this customer yet.</p>
                @else
                    <div class="max-h-96 space-y-2 overflow-y-auto bg-canvas/60 px-5 py-4" data-scroll-bottom>
                        @foreach ($messages as $m)
                            <div @class(['flex', 'justify-end' => $m->direction === 'out'])>
                                <div @class(['max-w-[80%] rounded-2xl px-3.5 py-2 text-sm whitespace-pre-line',
                                             'rounded-br-sm bg-brand-600 text-white' => $m->direction === 'out',
                                             'rounded-bl-sm border border-line bg-white' => $m->direction !== 'out'])>{{ $m->body ?: '['.$m->type.']' }}<span @class(['mt-1 block text-[11px]', 'text-white/70' => $m->direction === 'out', 'text-muted' => $m->direction !== 'out'])>{{ LocalTime::format($m->created_at, 'd M, g:i A') }}@if($m->direction === 'out') · {{ $m->status }}@endif</span></div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
            @endif
        </div>

        <div class="space-y-5">
            <section class="card p-5">
                <h2 class="font-semibold">Customer</h2>
                <p class="mt-3 font-medium">{{ $order->customer_label }}</p>
                @if ($order->customer)
                    <a href="https://wa.me/{{ $order->customer->wa_id }}" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1.5 text-sm text-brand-700 hover:underline">
                        <x-icon name="whatsapp" class="size-4" /> {{ $order->customer->phone }}
                    </a>
                @elseif ($order->guest_phone)
                    <p class="mt-1 text-sm text-muted">{{ $order->guest_phone }}</p>
                @endif
                @if ($order->diningTable)
                    <p class="mt-3 text-sm"><span class="text-muted">Table:</span> {{ $order->diningTable->name }}</p>
                @endif
                @if ($order->delivery_address)
                    <div class="mt-4 flex gap-2 rounded-xl bg-canvas p-3 text-sm">
                        <x-icon name="pin" class="mt-0.5 size-4 shrink-0 text-muted" />
                        <p class="whitespace-pre-line">{{ $order->delivery_address }}</p>
                    </div>
                @endif
                @if ($order->notes)
                    <p class="mt-3 text-sm"><span class="text-muted">Note:</span> {{ $order->notes }}</p>
                @endif
            </section>

            <section class="card p-5">
                <h2 class="font-semibold">Payment</h2>
                <p class="mt-3 text-sm">{{ $order->payment_method->label() }} ·
                    <span @class(['font-medium', 'text-emerald-700' => $order->payment_status->value === 'paid'])>{{ $order->payment_status->label() }}</span>
                    @if ($order->paid_at) <span class="text-muted">on {{ LocalTime::format($order->paid_at, 'd M, g:i A') }}</span> @endif
                </p>
                @if ($order->payment_status->value !== 'paid' && $order->status !== OrderStatus::Cancelled)
                    <form method="post" action="{{ route('orders.paid', $order) }}" class="mt-3 flex gap-2">
                        @csrf
                        <label for="pay-method" class="sr-only">Payment method</label>
                        <select id="pay-method" name="payment_method" class="field w-auto">
                            @foreach (\App\Enums\PaymentMethod::cases() as $m)
                                <option value="{{ $m->value }}" @selected($order->payment_method === $m)>{{ $m->label() }}</option>
                            @endforeach
                        </select>
                        <button class="btn-ghost flex-1">Mark as paid</button>
                    </form>
                @endif
            </section>

            <section class="card p-5">
                <h2 class="font-semibold">Timeline</h2>
                <ol class="mt-4 space-y-4 border-l border-line pl-4">
                    @foreach ($order->histories as $h)
                        <li class="relative text-sm">
                            <span class="absolute top-1.5 -left-[21px] size-2.5 rounded-full {{ $h->to_status->dotClass() }}"></span>
                            <p class="font-medium">{{ $h->from_status === $h->to_status ? ($h->note ?? 'Updated') : $h->to_status->label() }}</p>
                            <p class="text-xs text-muted">{{ LocalTime::format($h->created_at, 'd M, g:i A') }}@if($h->user) · {{ $h->user->name }}@endif</p>
                            @if ($h->note && $h->from_status !== $h->to_status)<p class="mt-0.5 text-xs text-muted">{{ $h->note }}</p>@endif
                        </li>
                    @endforeach
                </ol>
            </section>

            @if ($order->notifications->isNotEmpty())
                <section class="card p-5">
                    <h2 class="font-semibold">WhatsApp updates</h2>
                    <ul class="mt-3 space-y-3">
                        @foreach ($order->notifications as $n)
                            <li class="text-sm">
                                <div class="flex items-center justify-between gap-2">
                                    <span>{{ OrderStatus::tryFrom((string) $n->status_snapshot)?->label() ?? $n->event }}</span>
                                    <span @class(['text-xs font-medium',
                                                  'text-emerald-700' => $n->state === 'sent',
                                                  'text-amber-700' => $n->state === 'pending',
                                                  'text-rose-700' => $n->state === 'failed'])>{{ ucfirst($n->state) }}</span>
                                </div>
                                @if ($n->state === 'failed')
                                    <p class="mt-0.5 text-xs text-rose-700">{{ $n->last_error }}</p>
                                    <form method="post" action="{{ route('orders.notifications.retry', [$order, $n]) }}" class="mt-1">
                                        @csrf
                                        <button class="text-xs font-medium text-brand-700 hover:underline">Send again</button>
                                    </form>
                                @elseif ($n->state === 'pending')
                                    <p class="mt-0.5 text-xs text-muted">Waiting for the queue worker{{ $n->last_error ? ': '.$n->last_error : '' }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (in_array(OrderStatus::Cancelled, $next, true))
                <section class="card p-5">
                    <h2 class="font-semibold">Cancel order</h2>
                    <form method="post" action="{{ route('orders.status', $order) }}" class="mt-3 space-y-3"
                          data-confirm="Cancel order #{{ $order->order_number }}? The customer will be told on WhatsApp.">
                        @csrf
                        <input type="hidden" name="status" value="cancelled">
                        <label for="note" class="label">Reason (sent to the customer)</label>
                        <input id="note" name="note" required maxlength="255" class="field" placeholder="e.g. Item out of stock" value="{{ old('note') }}">
                        <button class="btn-danger w-full">Cancel order</button>
                    </form>
                </section>
            @endif
            @if ($order->status === OrderStatus::Cancelled && $order->cancel_reason)
                <p class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">Cancelled: {{ $order->cancel_reason }}</p>
            @endif
        </div>
    </div>
</x-layouts.admin>
