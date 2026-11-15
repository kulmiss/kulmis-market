<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional extra lock on the admin: when ADMIN_ALLOWED_IPS is set (comma
 * separated IPs or ranges, e.g. "196.188.0.0/16, 102.218.1.7"), the admin
 * page and admin API answer 404 to everyone else. Empty = no IP lock (2FA
 * still applies).
 */
class AdminIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) config('security.admin_allowed_ips')))));
        if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            abort(404);
        }

        return $next($request);
    }
}
