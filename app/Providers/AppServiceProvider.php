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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureLiveDiningRateLimits();
    }

    /**
     * Public Live Dining endpoints are keyed by table token, device pass and IP so one
     * noisy device cannot starve the rest of the table.
     */
    private function configureLiveDiningRateLimits(): void
    {
        $key = fn (Request $request): string => implode('|', [
            (string) $request->route('tableToken'),
            substr(hash('sha256', (string) $request->header('X-Dine-Visit')), 0, 16),
            (string) $request->ip(),
        ]);

        RateLimiter::for('dine-read', fn (Request $request) => Limit::perMinute(90)->by('read|'.$key($request)));
        RateLimiter::for('dine-entry', fn (Request $request) => Limit::perMinute(8)->by('entry|'.$request->route('tableToken').'|'.$request->ip()));
        RateLimiter::for('dine-order', fn (Request $request) => Limit::perMinute(10)->by('order|'.$key($request)));
        RateLimiter::for('dine-service', fn (Request $request) => Limit::perMinute(12)->by('service|'.$key($request)));
    }
}
