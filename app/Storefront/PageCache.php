<?php

namespace App\Storefront;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * Static page cache for guest pages: the HTML is written to
 * public/page-cache/{path}.html and nginx/Apache serve it without PHP
 * (see docs/deploy.md). Cleared whenever the catalog, settings or switches
 * change, and every 15 minutes (early-access timing).
 */
class PageCache
{
    public static function enabled(): bool
    {
        if (app()->runningUnitTests()) {
            return (bool) config('shop.page_cache_in_tests', false);
        }

        return (bool) config('shop.page_cache', true);
    }

    public static function cacheable(Request $request, Response $response): bool
    {
        return $request->isMethod('GET')
            && $request->getHttpHost() === self::canonicalHost()
            && $request->query->count() === 0
            && ! $request->cookies->has((string) config('session.cookie'))
            && $response->getStatusCode() === 200
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')
            && ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')
            && ! $request->is('admin', 'admin/*', 'pay/*', 'checkout', 'cart', 'track')
            && ! $response->headers->has('Set-Cookie')
            && $response->headers->getCookies() === [];
    }

    /** host[:port] of APP_URL: only requests for the real site name are cached. */
    public static function canonicalHost(): string
    {
        $url = parse_url((string) config('app.url'));
        $host = strtolower((string) ($url['host'] ?? ''));

        return isset($url['port']) ? $host.':'.$url['port'] : $host;
    }

    public static function path(Request $request): string
    {
        $path = trim($request->getPathInfo(), '/');
        if (! preg_match('#^[a-z0-9/_-]*$#', $path)) {
            return '';
        }

        return public_path('page-cache/'.($path === '' ? 'index' : $path).'.html');
    }

    public static function store(Request $request, Response $response): void
    {
        $file = self::path($request);
        if ($file === '') {
            return;
        }
        File::ensureDirectoryExists(dirname($file));
        File::put($file.'.tmp', (string) $response->getContent());
        File::move($file.'.tmp', $file);
    }

    public static function clear(): void
    {
        File::deleteDirectory(public_path('page-cache'));
    }
}
