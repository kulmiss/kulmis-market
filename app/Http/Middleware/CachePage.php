<?php

namespace App\Http\Middleware;

use App\Storefront\PageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Writes guest HTML to the static page cache (served by the web server next time). */
class CachePage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (PageCache::cacheable($request, $response)) {
            $response->headers->set('Cache-Control', 'public, max-age=300');
            if (PageCache::enabled()) {
                PageCache::store($request, $response);
                $response->headers->set('X-Page-Cache', 'stored');
            }
        }

        return $response;
    }
}
