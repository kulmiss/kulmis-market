<?php

use App\Storefront\PageCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
Schedule::command('orders:cancel-unpaid')->hourly()->withoutOverlapping();
Schedule::command('memberships:sweep')->hourly()->withoutOverlapping();
Schedule::command('signals:sweep')->hourly()->withoutOverlapping();
Schedule::command('referrals:release')->hourly()->withoutOverlapping();
Schedule::command('payouts:remind')->dailyAt('08:41');
Schedule::command('badges:check')->dailyAt('04:17')->withoutOverlapping();
Schedule::command('badges:check --top-referrer')->monthlyOn(1, '05:13')->withoutOverlapping();
Schedule::command('badges:bonus')->monthlyOn(1, '06:23')->withoutOverlapping();
Schedule::command('km:prune-logs')->weeklyOn(1, '03:23');
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:41');

// Heartbeat for the admin status page (shows if cron is really running).
Schedule::call(fn () => Cache::put('scheduler.heartbeat', now()->toIso8601String(), now()->addDay()))
    ->everyMinute()->name('heartbeat');
Schedule::call(fn () => PageCache::clear())->everyFifteenMinutes()->name('page-cache-refresh');

// After an update the CSS/JS file names change: cached pages must go (also runs with `php artisan optimize`).
Artisan::command('km:clear-page-cache', function () {
    PageCache::clear();
    $this->info('Page cache cleared.');
})->purpose('Delete the static page cache (public/page-cache)');

// Encrypted nightly backup (needs BACKUP_KEY). Copy backups off the server too.
if (config('shop.backup_key')) {
    Schedule::command('km:backup')->dailyAt('02:17')->withoutOverlapping();
}
