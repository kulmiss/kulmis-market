<?php

namespace App\Home;

/**
 * YouTube and Vimeo links for the Video section. Only these two sites can
 * be shown in the page (Content-Security-Policy frame-src), YouTube through
 * its no-cookie address. The player loads only after a tap (fast page).
 */
final class VideoLink
{
    public const HOSTS = ['https://www.youtube-nocookie.com', 'https://player.vimeo.com'];

    /** @return ?array{site:string, embed:string, watch:string} */
    public static function parse(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 300 || ! preg_match('#^https?://#i', $url)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $yt = null;
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            $yt = $path === '/watch' ? ($q['v'] ?? null) : (preg_match('#^/(shorts|embed|live)/([^/]+)#', $path, $m) ? $m[2] : null);
        } elseif ($host === 'youtu.be') {
            $yt = ltrim($path, '/');
        }
        if (is_string($yt) && preg_match('/^[A-Za-z0-9_-]{11}$/', $yt)) {
            return ['site' => 'YouTube', 'embed' => "https://www.youtube-nocookie.com/embed/$yt?autoplay=1&rel=0", 'watch' => "https://www.youtube.com/watch?v=$yt"];
        }
        if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true) && preg_match('#^/(?:video/)?(\d{5,12})(?:/|$)#', $path, $m)) {
            return ['site' => 'Vimeo', 'embed' => "https://player.vimeo.com/video/{$m[1]}?autoplay=1&dnt=1", 'watch' => "https://vimeo.com/{$m[1]}"];
        }

        return null;
    }
}
