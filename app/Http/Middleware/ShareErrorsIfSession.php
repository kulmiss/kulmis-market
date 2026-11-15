<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;

class ShareErrorsIfSession extends ShareErrorsFromSession
{
    public function handle($request, Closure $next): Response
    {
        if ($request->hasSession()) {
            return parent::handle($request, $next);
        }
        View::share('errors', new ViewErrorBag);

        return $next($request);
    }
}
