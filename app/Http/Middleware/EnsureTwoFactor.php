<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin screens need a session that passed 2FA. Staff cannot use personal
 * API tokens for the admin (tokens never went through the 2FA challenge).
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        if ($token !== null && ! $token instanceof TransientToken) {
            abort(403, 'Admin access requires a browser session with 2FA.');
        }

        if (config('permissions.require_2fa') && ! $user->hasTwoFactor()) {
            return response()->json([
                'message' => 'Set up two-factor authentication first.',
                'two_factor_setup_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
