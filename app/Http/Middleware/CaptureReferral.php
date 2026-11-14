<?php

namespace App\Http\Middleware;

use App\Referral\Attribution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Any page opened with ?ref=CODE remembers the code (latest wins). */
class CaptureReferral
{
    public function __construct(private readonly Attribution $attribution) {}

    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->query('ref');
        if (is_string($code) && preg_match('/^[A-Za-z0-9]{2,16}$/', $code)) {
            $this->attribution->rememberFromLink($code);
        }

        return $next($request);
    }
}
