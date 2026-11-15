<?php

namespace App\Support;

/**
 * Content-Security-Policy. The storefront's only inline script (theme
 * before paint) is allowed by hash, so cached static pages keep working.
 */
final class Csp
{
    public const THEME_SCRIPT = "(function(){try{var t=localStorage.getItem('km-theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark')}catch(e){}})();";

    public static function themeHash(): string
    {
        return "'sha256-".base64_encode(hash('sha256', self::THEME_SCRIPT, true))."'";
    }

    public static function storefront(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' ".self::themeHash(),
            // No inline <style> blocks at all; style="" attributes (badge colours, member accent) stay allowed.
            "style-src 'self'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
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
