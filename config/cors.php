<?php

/*
| Cross-origin requests. The website and admin are same-origin, and the
| future Flutter app doesn't need CORS, so only our own site is allowed.
| Add other origins (comma separated) in CORS_ALLOWED_ORIGINS if ever needed.
*/

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
    'allowed_origins' => array_values(array_filter(array_merge(
        [rtrim((string) env('APP_URL', 'http://localhost'), '/')],
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Cart-Token', 'Idempotency-Key'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
