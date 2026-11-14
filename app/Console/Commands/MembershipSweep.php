<?php

namespace App\Console\Commands;

use App\Membership\MembershipService;
use Illuminate\Console\Command;

class MembershipSweep extends Command
{
    protected $signature = 'memberships:sweep';

    protected $description = 'Move memberships to grace/expired, send renewal reminders and loyalty rewards.';

    public function handle(MembershipService $service): int
    {
        $stats = $service->sweep();
        $stats['loyalty'] = $service->grantLoyaltyRewards();
        $this->info(json_encode($stats) ?: '');

        return self::SUCCESS;
    }
}
