<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in · {{ app(\App\Services\Settings::class)->get('restaurant_name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center bg-navy-950 px-4">
    <main class="w-full max-w-sm">
        <div class="mb-6 flex items-center justify-center gap-2.5 text-white">
            <span class="grid size-10 place-items-center rounded-full bg-brand-500 text-lg font-semibold">{{ mb_substr(app(\App\Services\Settings::class)->get('restaurant_name'), 0, 1) }}</span>
            <span class="text-lg font-semibold">{{ app(\App\Services\Settings::class)->get('restaurant_name') }}</span>
        </div>
        <form method="post" action="{{ url('/login') }}" class="card space-y-4 p-6">
            @csrf
            <div>
                <h1 class="text-lg font-semibold">Log in to the admin panel</h1>
                <p class="mt-1 text-sm text-muted">Manage WhatsApp orders and the menu.</p>
            </div>
            @if ($errors->any())
                <p role="alert" class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ $errors->first() }}</p>
            @endif
            <div>
                <label for="email" class="label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="field">
            </div>
            <div>
                <label for="password" class="label">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password" class="field">
            </div>
            <label class="flex items-center gap-2 text-sm text-muted">
                <input type="checkbox" name="remember" value="1" class="rounded border-line text-brand-600"> Keep me logged in
            </label>
            <button class="btn-primary w-full">Log in</button>
        </form>
    </main>
</body>
</html>
