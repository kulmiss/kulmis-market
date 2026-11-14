<?php

namespace App\Console\Commands;

use App\Referral\CommissionEngine;
use Illuminate\Console\Command;

class ReleaseCommissions extends Command
{
    protected $signature = 'referrals:release';

    protected $description = 'Make earnings available after the pending (refund) window.';

    public function handle(CommissionEngine $engine): int
    {
        $this->info('Released: '.$engine->releaseDue());

        return self::SUCCESS;
    }
}
