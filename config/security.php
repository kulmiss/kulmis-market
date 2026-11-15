<?php

/*
| Account and money safety knobs. Defaults follow OWASP ASVS L2.
*/

return [
    // Passwords: length + not on the common list (+ optional breach check).
    'customer_password_min' => (int) env('CUSTOMER_PASSWORD_MIN', 10),
    'staff_password_min' => (int) env('ADMIN_PASSWORD_MIN', 12),
    'password_max' => 128,
    // Checks the password against known breaches with k-anonymity (only the
    // first 5 characters of its SHA-1 hash leave the server). If the service
    // can't be reached, the check is skipped.
    'pwned_check' => (bool) env('PASSWORD_PWNED_CHECK', env('APP_ENV') === 'production'),

    // Sign-in pause: after this many wrong passwords in a row the account
    // waits this many minutes (any channel: website, app, admin).
    'lockout_attempts' => (int) env('LOCKOUT_ATTEMPTS', 10),
    'lockout_minutes' => (int) env('LOCKOUT_MINUTES', 15),

    // Admin: signed out after this long without activity, and always after max_hours.
    'admin_idle_minutes' => (int) env('ADMIN_IDLE_MINUTES', 30),
    'admin_max_hours' => (int) env('ADMIN_MAX_HOURS', 12),

    // Optional: only these IPs/ranges may open the admin (comma separated). Empty = anyone (2FA still required).
    'admin_allowed_ips' => (string) env('ADMIN_ALLOWED_IPS', ''),

    // App (API) sign-ins expire after this many days.
    'token_days' => (int) env('API_TOKEN_DAYS', 30),

    // Email change: the confirmation link works this long.
    'email_change_hours' => 24,

    // Cookie that remembers a browser a customer signed in from (random id; only its hash is stored).
    'device_cookie' => 'km_device',
];
