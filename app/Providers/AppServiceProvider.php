<?php

namespace App\Providers;

use App\Models\User;
use App\Session\PrivacySessionHandler;
use App\Shipping\FreeShippingQuoter;
use App\Shipping\ShippingQuoter;
use App\Support\Features;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(ShippingQuoter::class, FreeShippingQuoter::class);
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

        Blade::if('feature', fn (string $key) => Features::enabled($key));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('login')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->getId()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
