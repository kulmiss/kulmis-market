<?php

namespace App\Console\Commands;

use App\Badges\BonusRun;
use App\Referral\CommissionEngine;
use App\Rewards\Rewards;
use Illuminate\Console\Command;

class ReleaseCommissions extends Command
{
    protected $signature = 'referrals:release';

    protected $description = 'Make earnings, badge bonuses, rewards and prizes ready to use after the on-the-way (refund) window.';

    public function handle(CommissionEngine $engine, BonusRun $bonuses, Rewards $rewards): int
    {
        $this->info('Released: '.$engine->releaseDue());
        $this->info('Badge bonuses ready: '.$bonuses->releaseDue());
        $this->info('Rewards and prizes ready: '.$rewards->releaseDue());

        return self::SUCCESS;
    }
}
