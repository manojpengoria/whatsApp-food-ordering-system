<x-layouts.admin title="Staff">
    <div class="mb-5">
        <h1 class="text-2xl font-semibold tracking-tight">Staff</h1>
        <p class="mt-1 text-sm text-muted"><b>Administrator</b>: everything. <b>Staff</b>: orders, billing, tables, kitchen, inventory and customers — not menu, reports, expenses, staff or settings.</p>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1fr_340px]">
        <div class="card overflow-hidden">
            <ul class="divide-y divide-line">
                @foreach ($users as $u)
                    <li @class(['px-5 py-4', 'opacity-60' => ! $u->is_active])>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="grid size-9 place-items-center rounded-full bg-navy-900 text-sm font-medium text-white">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                            <div class="min-w-40 flex-1">
                                <p class="text-sm font-medium">{{ $u->name }} @if($u->is(auth()->user()))<span class="text-xs text-muted">(you)</span>@endif</p>
                                <p class="text-xs text-muted">{{ $u->email }}</p>
                            </div>
                            <span @class(['pill', 'bg-brand-50 text-brand-700 ring-brand-100' => $u->isAdmin(), 'bg-slate-50 text-slate-600 ring-slate-200' => ! $u->isAdmin()])>{{ $u->roleLabel() }}</span>
                            @unless ($u->is_active)<span class="pill bg-rose-50 text-rose-700 ring-rose-200">Deactivated</span>@endunless
                            <details>
                                <summary class="cursor-pointer text-sm text-brand-700">Edit</summary>
                                <form method="post" action="{{ route('staff.update', $u) }}" class="mt-3 grid gap-2 sm:grid-cols-2">
                                    @csrf @method('put')
                                    <input name="name" value="{{ $u->name }}" required maxlength="80" class="field" aria-label="Name">
                                    <select name="role" class="field" aria-label="Role">
                                        <option value="staff" @selected(! $u->isAdmin())>Staff</option>
                                        <option value="admin" @selected($u->isAdmin())>Administrator</option>
                                    </select>
                                    <input name="password" type="password" minlength="8" autocomplete="new-password" class="field" placeholder="New password (optional)" aria-label="New password">
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($u->is_active) class="rounded border-line text-brand-600"> Can log in</label>
                                    <button class="btn-primary sm:col-span-2">Save</button>
                                </form>
                            </details>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <section class="card p-5 xl:self-start">
            <h2 class="font-semibold">Add a person</h2>
            <form method="post" action="{{ route('staff.store') }}" class="mt-3 space-y-3">
                @csrf
                <div><label for="s-name" class="label">Name</label><input id="s-name" name="name" value="{{ old('name') }}" required maxlength="80" class="field"></div>
                <div><label for="s-email" class="label">Email (used to log in)</label><input id="s-email" name="email" type="email" value="{{ old('email') }}" required class="field" autocomplete="off"></div>
                <div><label for="s-role" class="label">Role</label><select id="s-role" name="role" class="field"><option value="staff">Staff</option><option value="admin">Administrator</option></select></div>
                <div><label for="s-pass" class="label">Password</label><input id="s-pass" name="password" type="password" minlength="8" required autocomplete="new-password" class="field" aria-describedby="s-pass-help"><p id="s-pass-help" class="mt-1 text-xs text-muted">At least 8 characters. Share it with them in person.</p></div>
                <button class="btn-primary w-full">Add</button>
            </form>
        </section>
    </div>
</x-layouts.admin>
