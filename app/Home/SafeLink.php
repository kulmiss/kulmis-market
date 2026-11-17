<?php

namespace App\Home;

/**
 * Links typed in the home page builder. Only these are allowed:
 *   /a/page (our own pages), #part-of-page, https://… or http://…,
 *   mailto:name@example.com, tel:+251…
 * Everything else (javascript:, data:, //other-site, spaces, control
 * characters) is refused, so a link can never run code.
 */
final class SafeLink
{
    public const HELP = 'Use a page of this shop (/shop), a full address (https://…), mailto:… or tel:…';

    public static function clean(?string $link): ?string
    {
        $link = trim((string) $link);
        if ($link === '' || strlen($link) > 500 || preg_match('/[\x00-\x20\x7f\\\\<>"\'`]/', $link)) {
            return null;
        }
        if ($link[0] === '#') {
            return preg_match('/^#[A-Za-z0-9_-]{1,60}$/', $link) ? $link : null;
        }
        if ($link[0] === '/') {
            // "//host" would leave the site; "/\host" is read the same way by some browsers.
            return isset($link[1]) && $link[1] === '/' ? null : $link;
        }
        $lower = strtolower($link);
        if (str_starts_with($lower, 'mailto:')) {
            return filter_var(substr($link, 7), FILTER_VALIDATE_EMAIL) ? $link : null;
        }
        if (str_starts_with($lower, 'tel:')) {
            return preg_match('/^\+?[0-9-]{5,20}$/', substr($link, 4)) ? $link : null;
        }
        if (str_starts_with($lower, 'https://') || str_starts_with($lower, 'http://')) {
            $host = parse_url($link, PHP_URL_HOST);

            // No "user@" part: https://shop.et@other.site would really open other.site.
            return is_string($host) && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host) && parse_url($link, PHP_URL_USER) === null ? $link : null;
        }

        return null;
    }

    /** For href="": our own pages become full addresses (like url('/shop')). */
    public static function href(?string $link): ?string
    {
        $clean = self::clean($link);
        if ($clean === null) {
            return null;
        }

        return $clean[0] === '/' ? url($clean) : $clean;
    }
}
