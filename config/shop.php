<?php

return [
    /*
     * Shop currency. All money is stored as integer minor units (santim,
     * 1 ETB = 100 santim) — never floats.
     */
    'currency' => env('SHOP_CURRENCY', 'ETB'),
    'minor_units' => 100,

    /*
     * When true the scheduler runs `queue:work --stop-when-empty` every
     * minute, so a single cron line is enough on shared hosting.
     */
    'queue_via_scheduler' => (bool) env('QUEUE_VIA_SCHEDULER', true),

    // Static HTML cache for guest pages (see docs/deploy.md for the server rules).
    'page_cache' => (bool) env('PAGE_CACHE', true),
    'page_cache_in_tests' => false,
];
