<?php

namespace App\Http;

/** Host names the app answers to in production: APP_URL's host plus TRUSTED_HOSTS. */
final class TrustedHosts
{
    /** @return list<string> anchored regex patterns for Request::setTrustedHosts() */
    public static function patterns(): array
    {
        $hosts = array_merge([parse_url((string) config('app.url'), PHP_URL_HOST)], explode(',', (string) config('app.trusted_hosts')));

        return array_values(array_map(
            fn (string $h) => '^'.preg_quote(strtolower(trim($h)), '/').'$',
            array_filter(array_map(fn ($h) => is_string($h) ? trim($h) : '', $hosts)),
        ));
    }
}
