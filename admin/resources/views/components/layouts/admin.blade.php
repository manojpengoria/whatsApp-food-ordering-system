@props(['title' => 'Dashboard', 'autoRefresh' => null])
@php
    $restaurantName = app(\App\Services\Settings::class)->get('restaurant_name');
    $newOrdersCount = \App\Models\Order::query()->where('status', \App\Enums\OrderStatus::Pending->value)->count();
    $latestOrderId = (int) (\App\Models\Order::query()->max('id') ?? 0);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ $restaurantName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen" data-live-url="{{ route('live.poll') }}" data-live-after="{{ $latestOrderId }}" data-live-now="{{ now()->timestamp }}" @if($autoRefresh) data-auto-refresh="{{ (int) $autoRefresh }}" @endif>
@php
    $isAdmin = auth()->user()->isAdmin();
    // [label, icon, route, active pattern, admin only]
    $nav = [
        ['Dashboard', 'dashboard', 'dashboard', 'dashboard', false],
        ['Orders', 'orders', 'orders.index', 'orders.*', false],
        ['Billing', 'billing', 'billing.index', 'billing.*', false],
        ['Tables', 'tables', 'tables.index', 'tables.*', false],
        ['Kitchen (KDS)', 'kitchen', 'kitchen', 'kitchen', false],
        ['Menu', 'menu', 'menu.index', 'menu.*', true],
        ['Inventory', 'inventory', 'inventory.index', 'inventory.*', false],
        ['Customers', 'customers', 'customers.index', 'customers.*', false],
        ['Reports', 'reports', 'reports.index', 'reports.*', true],
        ['Expenses', 'expenses', 'expenses.index', 'expenses.*', true],
        ['Staff', 'staff', 'staff.index', 'staff.*', true],
        ['Settings', 'settings', 'settings.edit', 'settings.*', true],
    ];
    $nav = array_values(array_filter($nav, fn ($n) => $isAdmin || ! $n[4]));
@endphp

<div id="sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-navy-950/50 lg:hidden" data-sidebar-toggle></div>

<aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-navy-950 text-white transition-transform lg:translate-x-0">
    <div class="flex h-16 items-center gap-2.5 px-5">
        <span class="grid size-9 place-items-center rounded-full bg-brand-500 text-base font-semibold">{{ mb_substr($restaurantName, 0, 1) }}</span>
        <div class="leading-tight">
            <p class="font-semibold">{{ $restaurantName }}</p>
            <p class="text-xs text-white/50">WhatsApp ordering</p>
        </div>
    </div>

    <nav class="mt-3 flex-1 space-y-0.5 overflow-y-auto px-3" aria-label="Main">
        @foreach ($nav as [$label, $icon, $route, $pattern])
            @if ($route)
                @php $active = request()->routeIs($pattern); @endphp
                <a href="{{ route($route) }}"
                   @class(['flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-colors',
                           'bg-brand-600 font-medium text-white' => $active,
                           'text-white/75 hover:bg-navy-800 hover:text-white' => ! $active])
                   @if($active) aria-current="page" @endif>
                    <x-icon :name="$icon" />
                    <span class="flex-1">{{ $label }}</span>
                    @if ($route === 'orders.index' && $newOrdersCount)
                        <span class="rounded-full bg-white/15 px-2 text-xs">{{ $newOrdersCount }}</span>
                    @endif
                </a>
            @endif
        @endforeach
    </nav>

    <div class="m-3 rounded-xl border border-white/10 bg-navy-900 p-4">
        <p class="text-sm font-medium">White Messenger</p>
        <p class="mt-0.5 text-xs text-white/50">Restaurant ordering on WhatsApp</p>
    </div>
</aside>

<div class="lg:pl-64">
    <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-white/95 px-4 backdrop-blur sm:px-6">
        <button type="button" class="btn-ghost px-2.5 lg:hidden" data-sidebar-toggle aria-label="Open menu">
            <x-icon name="menu-burger" />
        </button>

        <form action="{{ route('orders.index') }}" method="get" class="relative hidden max-w-md flex-1 sm:block" role="search">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
            <label for="global-search" class="sr-only">Search orders</label>
            <input id="global-search" name="q" value="{{ request('q') }}" type="search" placeholder="Search orders by number, name or phone"
                   class="field pl-9">
        </form>

        <div class="ml-auto flex items-center gap-2 sm:gap-4">
            <span class="hidden items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm md:inline-flex">
                <span class="size-2 rounded-full bg-emerald-500"></span>{{ $restaurantName }}
            </span>

            <button type="button" data-sound-toggle class="hidden items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5 text-xs text-muted hover:text-ink sm:inline-flex" aria-pressed="false">
                <span data-sound-label>Turn on order alarm</span>
            </button>
            <a href="{{ route('orders.index', ['status' => 'pending']) }}" class="relative rounded-lg p-2 text-muted hover:bg-canvas hover:text-ink"
               aria-label="New orders">
                <x-icon name="bell" />
                <span data-live-badge @class(['absolute top-1 right-1 grid min-w-4 place-items-center rounded-full bg-brand-600 px-1 text-[10px] font-semibold text-white', 'hidden' => ! $newOrdersCount])>{{ $newOrdersCount }}</span>
            </a>

            <div class="flex items-center gap-2.5">
                <span class="grid size-9 place-items-center rounded-full bg-navy-900 text-sm font-medium text-white">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                <div class="hidden leading-tight sm:block">
                    <p class="text-sm font-medium">{{ auth()->user()->name }}</p>
                    <p class="text-xs text-muted">{{ auth()->user()->roleLabel() }}</p>
                </div>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button class="rounded-lg p-2 text-muted hover:bg-canvas hover:text-ink" aria-label="Log out" title="Log out">
                        <x-icon name="logout" class="size-4.5" />
                    </button>
                </form>
            </div>
        </div>
    </header>

    <div class="pointer-events-none fixed top-20 right-4 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-3" data-live-toasts aria-live="assertive"></div>

    <main class="mx-auto max-w-[1400px] px-4 py-6 sm:px-6 lg:py-8">
        <x-flash />
        {{ $slot }}
    </main>
</div>
</body>
</html>
