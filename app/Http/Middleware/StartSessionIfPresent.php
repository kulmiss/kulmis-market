<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Storefront pages never create a session for guests (no cookie, no IP).
 * A session exists only after someone signs in (or opens a form that
 * needs CSRF protection), so only then is it resumed here.
 */
class StartSessionIfPresent extends StartSession
{
    public function handle($request, Closure $next): Response
    {
        if (! $request->cookies->has((string) config('session.cookie'))) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
