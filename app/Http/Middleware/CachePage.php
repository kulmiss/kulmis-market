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
            // Same as the web server sends for cached copies: the browser always checks back, so
            // signing in or out never shows a stale guest page from the browser cache.
            $response->headers->set('Cache-Control', 'public, max-age=0, must-revalidate');
            if (PageCache::enabled()) {
                PageCache::store($request, $response);
                $response->headers->set('X-Page-Cache', 'stored');
            }
        }

        return $response;
    }
}
