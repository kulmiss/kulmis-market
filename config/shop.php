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
];
