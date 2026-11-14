<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler
|--------------------------------------------------------------------------
| One cron line runs everything:
|   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
| Feature PRs add their jobs here (renewal reminders, pending → available
| earnings, Telegram removals, payout reminders, loyalty rewards...).
*/

if (config('shop.queue_via_scheduler')) {
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
        ->everyMinute()
        ->withoutOverlapping(5)
        ->name('queue-drain');
}

Schedule::command('queue:prune-failed --hours=720')->daily();
