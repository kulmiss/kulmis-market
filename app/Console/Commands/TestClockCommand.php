<?php

namespace App\Console\Commands;

use App\Storefront\PageCache;
use App\Support\TestClock;
use Illuminate\Console\Command;
use RuntimeException;

/** Local testing: pretend it is later, then run the daily jobs so you see the result at once. */
class TestClockCommand extends Command
{
    protected $signature = 'km:test-clock {by? : How far forward, e.g. +15days, +29days, +1month} {--reset : Back to the real time} {--no-jobs : Only move the clock}';

    protected $description = 'Local only: move the shop\'s clock forward (e.g. +15days) and run the daily jobs';

    /** The jobs that would have run in the meantime. */
    private const JOBS = ['memberships:sweep', 'signals:sweep', 'referrals:release', 'badges:check'];

    public function handle(): int
    {
        try {
            if ($this->option('reset')) {
                TestClock::reset();
                $this->info('Back to the real time: '.now()->format('D j M Y, H:i'));

                return self::SUCCESS;
            }
            if ($this->argument('by')) {
                $to = TestClock::forward((string) $this->argument('by'));
                $this->info('The shop now thinks it is '.$to->format('D j M Y, H:i').' (real time + '.round(TestClock::offset() / 86400, 1).' days).');
                if (! $this->option('no-jobs')) {
                    foreach (self::JOBS as $job) {
                        $this->line("  running $job …");
                        $this->callSilently($job);
                    }
                    PageCache::clear();
                    $this->info('Done: memberships, signals access, team earnings ("on the way" → "ready to use") and badges are up to date.');
                }

                return self::SUCCESS;
            }
            $this->line(TestClock::active()
                ? 'Pretend time: '.now()->format('D j M Y, H:i').' (real time + '.round(TestClock::offset() / 86400, 1).' days). Back: php artisan km:test-clock --reset'
                : 'Real time: '.now()->format('D j M Y, H:i').'. Move forward: php artisan km:test-clock +15days');

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
