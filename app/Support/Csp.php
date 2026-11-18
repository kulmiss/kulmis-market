<?php

namespace App\Support;

use App\Home\VideoLink;

/**
 * Content-Security-Policy. The storefront's only inline script (theme
 * before paint) is allowed by hash, so cached static pages keep working.
 */
final class Csp
{
    public const THEME_SCRIPT = "(function(){try{var t=localStorage.getItem('km-theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark')}catch(e){}})();";

    /**
     * Instant navigation: Chrome/Edge fetch a public page as soon as the pointer
     * rests on its link (~200 ms) or a finger touches it. Private and money pages
     * are never fetched ahead. (Other browsers: tiny fallback in app.js.)
     */
    public const SPECULATION_RULES = '{"prefetch":[{"where":{"and":[{"href_matches":"/*"},{"not":{"href_matches":["/account*","/admin*","/logout","/login*","/register","/cart","/checkout","/pay/*","/api/*","/r/*","/*\\\\?*"]}},{"not":{"selector_matches":"[rel~=nofollow], [data-no-prefetch]"}}]},"eagerness":"moderate"}]}';

    public static function themeHash(): string
    {
        return self::hash(self::THEME_SCRIPT);
    }

    private static function hash(string $inline): string
    {
        return "'sha256-".base64_encode(hash('sha256', $inline, true))."'";
    }

    public static function storefront(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' ".self::themeHash().' '.self::hash(self::SPECULATION_RULES),
            // No inline <style> blocks at all; style="" attributes (badge colours, member accent) stay allowed.
            "style-src 'self'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            // Home page videos: only the YouTube (no-cookie) and Vimeo players, after a tap.
            'frame-src '.implode(' ', VideoLink::HOSTS),
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
            ...self::upgrade(),
        ]);
    }

    public static function admin(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            ...self::upgrade(),
        ]);
    }

    /** @return list<string> */
    private static function upgrade(): array
    {
        return str_starts_with((string) config('app.url'), 'https://') ? ['upgrade-insecure-requests'] : [];
    }
}
