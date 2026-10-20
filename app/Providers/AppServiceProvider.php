<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request, so each role's permissions are read from the database only once.
        $this->app->scoped(\App\Services\PermissionService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Dispense > Search resident: results update while the staff type, so allow plenty of searches.
        // A named limiter keeps its own count (a plain "throttle:N,1" shares one count per user across routes).
        RateLimiter::for('resident-search', fn (Request $request) => Limit::perMinute(120)
            ->by('resident-search|' . ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
