<?php

namespace App\Providers;

use App\Services\Settings;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One Settings instance per request (and per queued job).
        $this->app->scoped(Settings::class);
    }

    public function boot(): void
    {
        Paginator::useTailwind();
    }
}
