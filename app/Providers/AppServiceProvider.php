<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters for the mobile API (`routes/api.php`).
     */
    private function configureRateLimiting(): void
    {
        // Brute-force protection on login — same 5-attempts-per-minute,
        // email+IP key as the web LoginRequest already uses in this app
        // (see app/Http/Requests/Auth/LoginRequest.php), so both surfaces
        // behave identically instead of two different tuned thresholds.
        RateLimiter::for('mobile-login', function ($request) {
            $key = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });

        // General mobile API traffic (pull master data + push sales),
        // keyed per TOKEN (not per user) — a cashier account with several
        // devices gets one bucket per device, so one busy HP can't throttle
        // another. 100/min is deliberately generous: a full pull is 2
        // requests, and pushing even a large backlog of offline sales is
        // sequential (one POST per sale, each with real network latency) —
        // legitimate sync traffic should never realistically brush this
        // ceiling, it exists to bound a misbehaving/abused token.
        RateLimiter::for('mobile-api', function ($request) {
            $key = $request->user()?->currentAccessToken()?->id ?? $request->ip();

            return Limit::perMinute(100)->by((string) $key);
        });
    }
}
