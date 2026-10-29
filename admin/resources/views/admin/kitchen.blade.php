@use('App\Enums\OrderStatus')
@use('App\Support\LocalTime')
<x-layouts.admin title="Kitchen" :auto-refresh="20">
    <div data-refresh-on-order class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Kitchen board</h1>
            <p class="mt-1 text-sm text-muted">Refreshes every 20 seconds. Oldest orders first.</p>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($columns as $col)
            @php $list = $orders[$col->value] ?? collect(); @endphp
            <section class="flex flex-col rounded-2xl bg-white/60 p-3 ring-1 ring-line" aria-label="{{ $col->label() }} orders">
                <h2 class="mb-3 flex items-center gap-2 px-1 text-sm font-semibold">
                    <span class="size-2 rounded-full {{ $col->dotClass() }}"></span>{{ $col->label() }}
                    <span class="ml-auto rounded-full bg-canvas px-2 text-xs font-medium text-muted">{{ $list->count() }}</span>
                </h2>
                <div class="space-y-3">
                    @forelse ($list as $o)
                        @php $forward = collect($o->status->nextStatuses($o->order_type))->first(fn ($s) => $s !== OrderStatus::Cancelled); @endphp
                        <article class="card p-3.5">
                            <div class="flex items-start justify-between gap-2">
                                <a href="{{ route('orders.show', $o) }}" class="font-semibold hover:text-brand-700">#{{ $o->order_number }}</a>
                                <span class="inline-flex items-center gap-1 text-xs text-muted"><x-icon name="clock" class="size-3.5" />{{ LocalTime::ago($o->created_at) }}</span>
                            </div>
                            <p class="text-xs text-muted">{{ $o->type_label }} · {{ $o->customer_label }}</p>
                            <ul class="mt-2.5 space-y-1 text-sm">
                                @foreach ($o->items as $item)
                                    <li class="flex gap-2"><span class="w-7 shrink-0 font-semibold">{{ $item->quantity }}×</span><span>{{ $item->item_name }}</span></li>
                                @endforeach
                            </ul>
                            @if ($o->notes)
                                <p class="mt-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">{{ $o->notes }}</p>
                            @endif
                            @if ($forward)
                                <form method="post" action="{{ route('orders.status', $o) }}" class="mt-3">
                                    @csrf
                                    <input type="hidden" name="status" value="{{ $forward->value }}">
                                    <input type="hidden" name="redirect" value="kitchen">
                                    <button class="btn-primary w-full py-1.5">{{ $forward->actionLabel($o->order_type) }}</button>
                                </form>
                            @endif
                        </article>
                    @empty
                        <p class="rounded-xl border border-dashed border-line px-3 py-6 text-center text-xs text-muted">Nothing here</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</x-layouts.admin>
