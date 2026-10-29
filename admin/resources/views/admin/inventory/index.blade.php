<x-layouts.admin title="Inventory">
    <div class="mb-5">
        <h1 class="text-2xl font-semibold tracking-tight">Inventory</h1>
        <p class="mt-1 text-sm text-muted">Stock is counted only for items with tracking on. Each order takes stock away; a cancelled order puts it back. At zero, the item disappears from WhatsApp and billing.</p>
    </div>

    @php $tabs = ['all' => 'All items', 'tracked' => 'Tracked', 'low' => 'Low stock', 'out' => 'Sold out']; @endphp
    <nav class="mb-4 flex gap-1 overflow-x-auto" aria-label="Filter">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('inventory.index', ['filter' => $key]) }}" @class(['shrink-0 rounded-lg px-3 py-1.5 text-sm', 'bg-navy-950 text-white' => $filter === $key, 'text-muted hover:bg-white hover:text-ink' => $filter !== $key])>
                {{ $label }} <span class="opacity-60">{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="card overflow-hidden">
        @if ($items->isEmpty())
            <p class="px-6 py-14 text-center text-sm text-muted">Nothing here.</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($items as $item)
                    <li class="flex flex-wrap items-center gap-4 px-5 py-3">
                        <span class="w-10 shrink-0 rounded-md bg-canvas py-1 text-center text-xs font-semibold">{{ $item->code }}</span>
                        <div class="min-w-40 flex-1">
                            <p class="text-sm font-medium">{{ $item->name }}</p>
                            <p class="text-xs text-muted">{{ $item->category?->name }}</p>
                        </div>

                        <div class="w-28 text-sm">
                            @if (! $item->track_stock)
                                <span class="text-muted">Not tracked</span>
                            @elseif ($item->isOutOfStock())
                                <span class="pill bg-rose-50 text-rose-700 ring-rose-200">Sold out</span>
                            @elseif ($item->isLowStock())
                                <span class="pill bg-amber-50 text-amber-700 ring-amber-200">{{ $item->stock_qty }} left</span>
                            @else
                                <span class="font-semibold">{{ $item->stock_qty }}</span> <span class="text-muted">in stock</span>
                            @endif
                        </div>

                        @if ($item->track_stock)
                            <form method="post" action="{{ route('inventory.adjust', $item) }}" class="flex items-center gap-1.5">
                                @csrf
                                <label class="sr-only" for="mode-{{ $item->id }}">Action</label>
                                <select id="mode-{{ $item->id }}" name="mode" class="field w-auto py-1.5">
                                    <option value="add">Add</option>
                                    <option value="remove">Remove</option>
                                    <option value="set">Set to</option>
                                </select>
                                <label class="sr-only" for="qty-{{ $item->id }}">Quantity</label>
                                <input id="qty-{{ $item->id }}" name="quantity" type="number" min="0" max="100000" required class="field w-20 py-1.5" placeholder="0">
                                <input name="note" maxlength="255" class="field hidden w-36 py-1.5 lg:block" placeholder="Note (optional)" aria-label="Note">
                                <button class="btn-ghost py-1.5">Update</button>
                            </form>
                            <a href="{{ route('inventory.history', $item) }}" class="text-sm text-brand-700 hover:underline">History</a>
                        @endif

                        @if (auth()->user()->isAdmin())
                            <details class="relative">
                                <summary class="cursor-pointer text-sm text-muted hover:text-ink">Settings</summary>
                                <form method="post" action="{{ route('inventory.settings', $item) }}" class="absolute right-0 z-10 mt-2 w-64 space-y-3 rounded-xl border border-line bg-white p-4 shadow-lg">
                                    @csrf @method('put')
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="track_stock" value="1" @checked($item->track_stock) class="rounded border-line text-brand-600"> Track stock</label>
                                    <div><label class="label" for="low-{{ $item->id }}">Warn when at or below</label><input id="low-{{ $item->id }}" name="low_stock_at" type="number" min="0" max="10000" value="{{ $item->low_stock_at }}" class="field"></div>
                                    <button class="btn-primary w-full">Save</button>
                                </form>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.admin>
