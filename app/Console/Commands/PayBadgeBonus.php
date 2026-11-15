<?php

namespace App\Console\Commands;

use App\Badges\BonusCalculator;
use App\Badges\BonusRun;
use App\Support\Money;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** 1st of the month: badge bonuses for the month that ended. Safe to run again. */
class PayBadgeBonus extends Command
{
    protected $signature = 'badges:bonus {--month= : A finished month like 2026-10 (pays it now)} {--dry-run : Only show the list}';

    protected $description = 'Pay the monthly badge bonuses for last month (once; waits for the first payout by hand).';

    public function handle(BonusRun $run, BonusCalculator $calculator): int
    {
        try {
            $month = $this->option('month') ? BonusRun::monthOf((string) $this->option('month')) : BonusRun::lastMonth();
            if ($this->option('dry-run')) {
                $pool = $calculator->plan($month)['pools'][0] ?? null;
                $this->info($month->format('F Y').': '.count($pool['lines'] ?? []).' bonuses, '.Money::format($pool['paid'] ?? 0)
                    .' of a '.Money::format($pool['budget'] ?? 0).' budget'.(($pool['adjusted'] ?? false) ? ' (adjusted)' : '').'.');
                foreach ($pool['lines'] ?? [] as $l) {
                    $this->line(sprintf('  #%d %s · %s · %s', $l['user_id'], $l['name'], $l['badge'], Money::format($l['amount'])));
                }

                return self::SUCCESS;
            }
            $this->info($this->option('month') ? 'Paid '.Money::format($run->pay($month, null)->paid).'.' : $run->scheduled());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
