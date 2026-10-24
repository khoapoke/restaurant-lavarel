<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Reservation;
use App\Observers\GuestCommunicationObserver;
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
        Reservation::observe(GuestCommunicationObserver::class);
        Order::observe(GuestCommunicationObserver::class);
        Payment::observe(GuestCommunicationObserver::class);
        $this->configureLiveDiningRateLimits();
        $this->configureCommunicationRateLimits();
    }

    /**
     * Mỗi nhóm thao tác một bucket riêng theo tài khoản: khách (chat), đánh giá bữa ăn, nhân viên hỗ trợ và
     * gửi lại email của quản trị không tiêu hạn mức của nhau.
     */
    private function configureCommunicationRateLimits(): void
    {
        $limit = fn (Request $request, string $bucket, string $setting): Limit => Limit::perMinute((int) config('communications.rate_limits.'.$setting))
            ->by($bucket.'|'.$request->user()?->getAuthIdentifier());

        RateLimiter::for('support-customer', fn (Request $request) => $limit($request, 'support-customer', 'customer_per_minute'));
        RateLimiter::for('notice-feedback', fn (Request $request) => $limit($request, 'notice-feedback', 'customer_per_minute'));
        RateLimiter::for('support-staff', fn (Request $request) => $limit($request, 'support-staff', 'staff_per_minute'));
        RateLimiter::for('email-retry', fn (Request $request) => $limit($request, 'email-retry', 'email_retry_per_minute'));
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
