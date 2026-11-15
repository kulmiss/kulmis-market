<?php

use App\Http\Middleware\AdminIpAllowlist;
use App\Http\Middleware\AdminSessionTimeout;
use App\Http\Middleware\CachePage;
use App\Http\Middleware\CaptureReferral;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\NormalizeQuery;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ShareErrorsIfSession;
use App\Http\Middleware\StartSessionIfPresent;
use App\Http\Middleware\StripControlCharacters;
use App\Http\Middleware\ThrottlePerRoute;
use App\Http\TrustedHosts;
use App\Pricing\PricingException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('storefront')->group(base_path('routes/storefront.php'));
            Route::middleware(['web', AdminIpAllowlist::class])->prefix('api/v1/admin')->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->append(StripControlCharacters::class);
        $middleware->prepend(NormalizeQuery::class);
        // Production only (Laravel skips this locally and in tests): any other Host header gets 400.
        $middleware->trustHosts(at: fn () => TrustedHosts::patterns(), subdomains: false);
        $middleware->group('storefront', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSessionIfPresent::class,
            ShareErrorsIfSession::class,
            SubstituteBindings::class,
            CaptureReferral::class,
            CachePage::class,
        ]);
        $middleware->alias([
            'feature' => EnsureFeature::class,
            'staff' => EnsureStaff::class,
            'admin.2fa' => EnsureTwoFactor::class,
            'admin.timeout' => AdminSessionTimeout::class,
            'throttle' => ThrottlePerRoute::class,
        ]);
        $middleware->throttleApi();
        // Essential cookies only (cart token, referral code) — also readable by the API.
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
        ]);
        $middleware->web(append: [CaptureReferral::class]);
        // API callers get a JSON 401; people browsing the shop go to /login.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (PricingException $e) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
