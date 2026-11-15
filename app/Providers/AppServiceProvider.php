<?php

namespace App\Providers;

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
use App\Payments\ChapaGateway;
use App\Referral\Attribution;
use App\Referral\ReferralListener;
use App\Session\PrivacySessionHandler;
use App\Shipping\ShippingQuoter;
use App\Shipping\ZoneShippingQuoter;
use App\Signals\MembershipSignalsListener;
use App\Storefront\PageCache;
use App\Support\Currency;
use App\Support\Features;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CheckoutExtras::class);
        $this->app->bind(ShippingQuoter::class, ZoneShippingQuoter::class);
        $this->app->bind(SmsGateway::class, fn () => match (config('services.sms.driver')) {
            'http' => new HttpSmsGateway,
            default => new LogSmsGateway,
        });
    }

    public function boot(): void
    {
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
        Event::listen(CatalogChanged::class, fn () => PageCache::clear());

        Blade::if('feature', fn (string $key) => Features::enabled($key));
        // Chapa is on AND accepts the shop currency (Settings → General).
        Blade::if('chapa', fn () => Features::enabled('payments.chapa') && app(ChapaGateway::class)->supports(Currency::code()));

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
