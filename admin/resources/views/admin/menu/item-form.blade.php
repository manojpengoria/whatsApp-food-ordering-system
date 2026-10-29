@use('App\Support\Money')
<x-layouts.admin :title="$item->exists ? 'Edit '.$item->name : 'Add item'">
    <a href="{{ route('menu.index') }}" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink"><x-icon name="arrow-left" class="size-4" /> Menu</a>
    <h1 class="mb-5 text-2xl font-semibold tracking-tight">{{ $item->exists ? 'Edit item' : 'Add item' }}</h1>

    <form method="post" action="{{ $item->exists ? route('menu.items.update', $item) : route('menu.items.store') }}" class="card max-w-2xl space-y-5 p-6" enctype="multipart/form-data">
        @csrf
        @if ($item->exists) @method('put') @endif

        <div class="grid gap-5 sm:grid-cols-[1fr_140px]">
            <div>
                <label for="name" class="label">Name</label>
                <input id="name" name="name" required maxlength="120" value="{{ old('name', $item->name) }}" class="field">
            </div>
            <div>
                <label for="code" class="label">WhatsApp code</label>
                <input id="code" name="code" required maxlength="10" value="{{ old('code', $item->code) }}" class="field uppercase" aria-describedby="code-help">
            </div>
        </div>
        <p id="code-help" class="-mt-3 text-xs text-muted">Customers type this code to order, for example “ADD {{ old('code', $item->code) ?: '3' }} 2”. Short numbers work best.</p>

        <div>
            <span class="label">Photo <span class="font-normal text-muted">(needed for the WhatsApp catalog)</span></span>
            <div class="flex flex-wrap items-start gap-4">
                <img id="image-preview" src="{{ $item->image_url }}" alt="" @class(['size-28 rounded-xl border border-line object-cover', 'hidden' => ! $item->image_url])>
                <div class="min-w-0 flex-1 space-y-2">
                    <input id="image" name="image" type="file" accept="image/jpeg,image/png" data-preview="#image-preview"
                           class="block w-full text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                           aria-describedby="image-help">
                    <p id="image-help" class="text-xs text-muted">JPG or PNG, at least 500 × 500 px, up to 5 MB. A square photo looks best.</p>
                    @if ($item->image_url)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_image" value="1" class="rounded border-line text-brand-600"> Remove current photo</label>
                    @endif
                </div>
            </div>
        </div>

        <div>
            <label for="description" class="label">Description <span class="font-normal text-muted">(optional)</span></label>
            <input id="description" name="description" maxlength="255" value="{{ old('description', $item->description) }}" class="field">
        </div>

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label for="menu_category_id" class="label">Category</label>
                <select id="menu_category_id" name="menu_category_id" required class="field">
                    <option value="">Choose…</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected((int) old('menu_category_id', $item->menu_category_id) === $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="price" class="label">Price (₹)</label>
                <input id="price" name="price" required inputmode="decimal" value="{{ old('price', $item->price_paise ? Money::toRupeesString($item->price_paise) : '') }}" class="field" placeholder="179">
            </div>
            <div>
                <label for="sort_order" class="label">Order in category</label>
                <input id="sort_order" name="sort_order" type="number" min="0" max="999" value="{{ old('sort_order', $item->sort_order ?? 0) }}" class="field">
            </div>
        </div>

        <fieldset class="flex flex-wrap gap-6">
            <legend class="sr-only">Options</legend>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_veg" value="1" @checked(old('is_veg', $item->is_veg)) class="rounded border-line text-brand-600"> Vegetarian</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_available" value="1" @checked(old('is_available', $item->is_available)) class="rounded border-line text-brand-600"> Available on WhatsApp</label>
        </fieldset>

        <div class="flex flex-wrap items-center gap-3 border-t border-line pt-5">
            <button class="btn-primary">{{ $item->exists ? 'Save item' : 'Add item' }}</button>
            <a href="{{ route('menu.index') }}" class="btn-ghost">Cancel</a>
        </div>
    </form>

    @if ($item->exists)
        <form method="post" action="{{ route('menu.items.destroy', $item) }}" class="mt-4 max-w-2xl" data-confirm="Delete “{{ $item->name }}” from the menu?">
            @csrf @method('delete')
            <button class="text-sm text-rose-700 hover:underline">Delete this item</button>
        </form>
    @endif
</x-layouts.admin>
