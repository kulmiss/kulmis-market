<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:signals` — a disabled feature answers 404 so it is hidden
 * everywhere (pages, API, admin) without deleting any data.
 * Several keys may be given: `feature:wallet,wallet.cashout` (all must be on).
 */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        foreach ($keys as $key) {
            abort_if(Features::disabled($key), 404);
        }

        return $next($request);
    }
}
