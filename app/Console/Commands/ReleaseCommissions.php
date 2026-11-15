<?php

namespace App\Console\Commands;

use App\Badges\BonusRun;
use App\Referral\CommissionEngine;
use Illuminate\Console\Command;

class ReleaseCommissions extends Command
{
    protected $signature = 'referrals:release';

    protected $description = 'Make earnings and badge bonuses ready to use after the on-the-way (refund) window.';

    public function handle(CommissionEngine $engine, BonusRun $bonuses): int
    {
        $this->info('Released: '.$engine->releaseDue());
        $this->info('Badge bonuses ready: '.$bonuses->releaseDue());

        return self::SUCCESS;
    }
}
