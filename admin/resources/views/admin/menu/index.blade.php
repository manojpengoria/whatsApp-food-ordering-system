@use('App\Support\LocalTime')
<x-layouts.admin title="Menu">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Menu</h1>
            <p class="mt-1 text-sm text-muted">{{ $itemCount }} items{{ $unavailableCount ? ", {$unavailableCount} hidden from WhatsApp" : '' }}. Customers order by typing the code, e.g. “ADD 3 2”.</p>
        </div>
        <a href="{{ route('menu.items.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" /> Add item</a>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1fr_320px]">
        <div class="space-y-5">
            @forelse ($categories as $cat)
                <section class="card overflow-hidden">
                    <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-3.5">
                        <h2 class="font-semibold">{{ $cat->name }}
                            @unless ($cat->is_active)<span class="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-normal text-muted">Hidden</span>@endunless
                        </h2>
                        <a href="{{ route('menu.items.create', ['category' => $cat->id]) }}" class="text-sm font-medium text-brand-700 hover:underline">Add item</a>
                    </div>
                    @if ($cat->items->isEmpty())
                        <p class="px-5 py-6 text-sm text-muted">No items in this category yet.</p>
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($cat->items as $item)
                                <li @class(['flex items-center gap-4 px-5 py-3', 'opacity-60' => ! $item->is_available])>
                                    <span class="w-10 shrink-0 rounded-md bg-canvas py-1 text-center text-xs font-semibold" title="WhatsApp code">{{ $item->code }}</span>
                                    @if ($item->image_url)
                                        <img src="{{ $item->image_url }}" alt="" class="size-11 shrink-0 rounded-lg object-cover" loading="lazy">
                                    @else
                                        <span class="grid size-11 shrink-0 place-items-center rounded-lg border border-dashed border-line text-[10px] text-muted" title="No photo — this item won't appear in the WhatsApp catalog">No photo</span>
                                    @endif
                                    <span class="grid size-4 shrink-0 place-items-center rounded-sm border {{ $item->is_veg ? 'border-emerald-600' : 'border-rose-700' }}" title="{{ $item->is_veg ? 'Veg' : 'Non-veg' }}">
                                        <span class="size-2 rounded-full {{ $item->is_veg ? 'bg-emerald-600' : 'bg-rose-700' }}"></span>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium">{{ $item->name }}</p>
                                        @if ($item->description)<p class="truncate text-xs text-muted">{{ $item->description }}</p>@endif
                                    </div>
                                    <span class="w-20 text-right text-sm font-medium">{{ $item->price }}</span>
                                    <form method="post" action="{{ route('menu.items.toggle', $item) }}">
                                        @csrf
                                        <button role="switch" aria-checked="{{ $item->is_available ? 'true' : 'false' }}" aria-label="Available on WhatsApp: {{ $item->name }}"
                                                @class(['relative h-6 w-11 rounded-full transition-colors', 'bg-brand-600' => $item->is_available, 'bg-slate-300' => ! $item->is_available])>
                                            <span @class(['absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', 'left-[22px]' => $item->is_available, 'left-0.5' => ! $item->is_available])></span>
                                        </button>
                                    </form>
                                    <a href="{{ route('menu.items.edit', $item) }}" class="text-sm text-brand-700 hover:underline">Edit</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @empty
                <p class="card px-6 py-14 text-center text-sm text-muted">Add a category on the right, then add items to it.</p>
            @endforelse
        </div>

        <aside class="space-y-5">
            <section class="card p-5">
                <h2 class="font-semibold">WhatsApp catalog</h2>
                <p class="mt-1 text-xs text-muted">Photo menu inside WhatsApp. Customers add items and tap “Send cart”.</p>

                @if ($catalogProblems)
                    <div class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        <p class="font-medium">Setup needed:</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-4">@foreach ($catalogProblems as $p)<li>{{ $p }}</li>@endforeach</ul>
                    </div>
                @endif

                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between gap-2"><dt class="text-muted">Last sync</dt><dd>{{ $catalogLastSync ? LocalTime::format(\Carbon\CarbonImmutable::parse($catalogLastSync), 'd M, g:i A') : 'Never' }}</dd></div>
                    @if ($catalogSummary)<div class="flex justify-between gap-2"><dt class="text-muted">Result</dt><dd class="text-right">{{ $catalogSummary }}</dd></div>@endif
                    <div class="flex justify-between gap-2"><dt class="text-muted">Items without photo</dt><dd @class(['text-amber-700' => $missingPhotos])>{{ $missingPhotos }}</dd></div>
                </dl>

                <div class="mt-4 flex flex-wrap gap-2">
                    <form method="post" action="{{ route('menu.catalog.sync') }}">@csrf<button class="btn-primary" @disabled($catalogProblems)>Sync to WhatsApp</button></form>
                    @if ($catalogLastSync)
                        <form method="post" action="{{ route('menu.catalog.status') }}">@csrf<button class="btn-ghost">Check status</button></form>
                    @endif
                </div>

                <form method="post" action="{{ route('menu.catalog.toggle') }}" class="mt-4 flex items-center justify-between gap-3 border-t border-line pt-4">
                    @csrf
                    <span class="text-sm">Bot shows the photo catalog</span>
                    <button role="switch" aria-checked="{{ $catalogEnabled ? 'true' : 'false' }}" aria-label="Bot shows the photo catalog"
                            @class(['relative h-6 w-11 shrink-0 rounded-full transition-colors', 'bg-brand-600' => $catalogEnabled, 'bg-slate-300' => ! $catalogEnabled])>
                        <span @class(['absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', 'left-[22px]' => $catalogEnabled, 'left-0.5' => ! $catalogEnabled])></span>
                    </button>
                </form>
                <p class="mt-2 text-xs text-muted">Turn this on after the first sync has finished. When off, the bot sends the text menu.</p>
            </section>

            <section class="card p-5">
                <h2 class="font-semibold">Add category</h2>
                <form method="post" action="{{ route('menu.categories.store') }}" class="mt-3 flex gap-2">
                    @csrf
                    <label for="new-cat" class="sr-only">Category name</label>
                    <input id="new-cat" name="name" required maxlength="80" class="field" placeholder="e.g. Desserts">
                    <button class="btn-primary">Add</button>
                </form>
            </section>

            <section class="card p-5">
                <h2 class="font-semibold">Categories</h2>
                <p class="mt-1 text-xs text-muted">Lower order number shows first on WhatsApp.</p>
                <div class="mt-3 space-y-3">
                    @foreach ($categories as $cat)
                        <details class="rounded-xl border border-line">
                            <summary class="cursor-pointer px-3 py-2 text-sm">{{ $cat->name }} <span class="text-muted">· {{ $cat->items->count() }}</span></summary>
                            <form method="post" action="{{ route('menu.categories.update', $cat) }}" class="space-y-2 border-t border-line p-3">
                                @csrf @method('put')
                                <input name="name" value="{{ $cat->name }}" required maxlength="80" class="field" aria-label="Category name">
                                <div class="flex items-center gap-3">
                                    <label class="text-xs text-muted">Order <input name="sort_order" type="number" min="0" max="999" value="{{ $cat->sort_order }}" class="field mt-1 w-20"></label>
                                    <label class="mt-4 flex items-center gap-1.5 text-sm"><input type="checkbox" name="is_active" value="1" @checked($cat->is_active) class="rounded border-line text-brand-600"> Show on WhatsApp</label>
                                </div>
                                <button class="btn-ghost w-full">Save category</button>
                            </form>
                            <form method="post" action="{{ route('menu.categories.destroy', $cat) }}" class="px-3 pb-3" data-confirm="Delete category “{{ $cat->name }}”?">
                                @csrf @method('delete')
                                <button class="text-xs text-rose-700 hover:underline">Delete category</button>
                            </form>
                        </details>
                    @endforeach
                </div>
            </section>
        </aside>
    </div>
</x-layouts.admin>
