<?php

namespace App\Http\Middleware;

use App\Support\Activity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin sessions end after `security.admin_idle_minutes` without activity and
 * always after `security.admin_max_hours`, even if active.
 */
class AdminSessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $now = now()->getTimestamp();
        $seen = (int) $session->get('admin.last_seen_at', $now);
        $started = (int) $session->get('admin.signed_in_at', $now);
        $idle = (int) config('security.admin_idle_minutes') * 60;
        $max = (int) config('security.admin_max_hours') * 3600;

        if (($idle > 0 && $now - $seen > $idle) || ($max > 0 && $now - $started > $max)) {
            Activity::log('security.admin_session_expired', $request->user(), ['idle' => $now - $seen], null, $request->user()?->id);
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return response()->json(['message' => 'You were signed out after a period of inactivity. Please sign in again.', 'session_expired' => true], 401);
        }
        $session->put('admin.last_seen_at', $now);
        if (! $session->has('admin.signed_in_at')) {
            $session->put('admin.signed_in_at', $now);
        }

        return $next($request);
    }
}
