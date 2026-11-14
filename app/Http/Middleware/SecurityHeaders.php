<?php

namespace App\Http\Middleware;

use App\Support\Csp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers (OWASP ASVS V14). A full CSP is added with the
 * storefront PR once the asset list is final.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $type = (string) $headers->get('Content-Type');
        if (! $headers->has('Content-Security-Policy') && ($type === '' || str_starts_with($type, 'text/html'))) {
            $headers->set('Content-Security-Policy', $request->is('admin', 'admin/*') ? Csp::admin() : Csp::storefront());
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
