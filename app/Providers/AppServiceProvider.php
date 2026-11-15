<?php

namespace App\Providers;

use App\Badges\BadgeEngine;
use App\Badges\BadgeListener;
use App\Events\CatalogChanged;
use App\Events\CustomerRegistered;
use App\Events\MembershipActivated;
use App\Events\MembershipEnded;
use App\Events\OrderStatusChanged;
use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;
use App\Models\User;
use App\Notify\OrderNotifier;
use App\Notify\Sms\HttpSmsGateway;
use App\Notify\Sms\LogSmsGateway;
use App\Notify\Sms\SmsGateway;
use App\Orders\CheckoutExtras;
use App\Referral\Attribution;
use App\Referral\ReferralListener;
use App\Session\PrivacySessionHandler;
use App\Shipping\ShippingQuoter;
use App\Shipping\ZoneShippingQuoter;
use App\Shop\Bundles;
use App\Shop\FlashSales;
use App\Signals\MembershipSignalsListener;
use App\Storefront\PageCache;
use App\Support\Currency;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request / queue job, so it can remember what it already worked out.
        $this->app->scoped(BadgeEngine::class);
        $this->app->scoped(FlashSales::class);
        $this->app->scoped(Bundles::class);
        $this->app->singleton(CheckoutExtras::class);
        $this->app->bind(ShippingQuoter::class, ZoneShippingQuoter::class);
        $this->app->bind(SmsGateway::class, fn () => match (config('services.sms.driver')) {
            'http' => new HttpSmsGateway,
            default => new LogSmsGateway,
        });
    }

    public function boot(): void
    {
        // Every link, asset and image URL uses APP_URL, never the Host header a
        // visitor sends (stops host-header injection into cached pages and emails).
        URL::forceRootUrl(rtrim((string) config('app.url'), '/'));
        // A queue worker runs for hours: every job reads the current switches and settings.
        Queue::before(function (): void {
            Features::forgetMemo();
            Settings::forgetMemo();
            Currency::flush();
        });
        // `php artisan optimize` (every deploy) also empties the page cache: new CSS/JS names.
        $this->optimizes(optimize: 'km:clear-page-cache', clear: 'km:clear-page-cache', key: 'page cache');

        // Development: every query slower than SLOW_QUERY_MS (default 50 ms) goes to the log.
        $slow = (int) config('database.slow_query_ms');
        if ($slow > 0 && ! $this->app->isProduction()) {
            DB::listen(function (QueryExecuted $q) use ($slow) {
                if ($q->time >= $slow) {
                    Log::warning('Slow query ('.round($q->time).' ms)', ['sql' => $q->sql, 'url' => request()->fullUrl()]);
                }
            });
        }
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Database sessions without guest IPs.
        Session::extend('privacy-database', function ($app) {
            return new PrivacySessionHandler(
                $app['db']->connection($app['config']['session.connection']),
                $app['config']['session.table'],
                $app['config']['session.lifetime'],
                $app,
            );
        });

        // Super Admin can do everything; staff roles by ability list.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);
        foreach (config('permissions.abilities') as $ability) {
            Gate::define($ability, fn (User $user) => $user->hasAbility($ability));
        }
        // Seeing payments: whoever handles orders, approves transfers or does refunds.
        Gate::define('view-payments', fn (User $user) => $user->hasAbility('manage-orders') || $user->hasAbility('approve-payments') || $user->hasAbility('manage-ledger'));
        // Help centre messages: content editors and support staff.
        Gate::define('answer-help', fn (User $user) => $user->hasAbility('manage-content') || $user->hasAbility('manage-members'));

        Event::listen(OrderStatusChanged::class, [OrderNotifier::class, 'handle']);

        Event::listen(MembershipActivated::class, [MembershipSignalsListener::class, 'activated']);
        Event::listen(MembershipEnded::class, [MembershipSignalsListener::class, 'ended']);

        // Referral program.
        Event::listen(PaymentSucceeded::class, [ReferralListener::class, 'paid']);
        Event::listen(PaymentSucceeded::class, [BadgeListener::class, 'paid']); // after commissions exist
        Event::listen(PaymentRefunded::class, [ReferralListener::class, 'refunded']);
        Event::listen(CustomerRegistered::class,
            fn (CustomerRegistered $e) => app(Attribution::class)->attachAtSignup($e->request, $e->user));
        $this->app->make(CheckoutExtras::class)->add(
            fn ($request, $cart, $user, $data) => app(Attribution::class)->forOrder($request, $user, $data));

        // Static page cache: anything shown on public pages changed → purge.
        Event::listen(CatalogChanged::class, function (): void {
            PageCache::clear();
            app(Bundles::class)->forget(); // bundles keep product names, prices and costs in their cache
        });

        Blade::if('feature', fn (string $key) => Features::enabled($key));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('login')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
        // Sign-ups: a few per hour per network, so bulk fake accounts are slow and noisy.
        // (Relaxed on a local install so you can test many accounts from one PC.)
        RateLimiter::for('register', fn (Request $request) => app()->environment('local') ? Limit::perMinute(60)->by($request->ip()) : [
            Limit::perHour(5)->by('h|'.$request->ip()),
            Limit::perDay(20)->by('d|'.$request->ip()),
        ]);
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->getId()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
