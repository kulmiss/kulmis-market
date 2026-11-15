<?php

namespace App\Console\Commands;

use App\Badges\BadgeEngine;
use App\Models\User;
use Illuminate\Console\Command;

class CheckBadges extends Command
{
    protected $signature = 'badges:check {--top-referrer : Also award last month\'s top referrer badges}';

    protected $description = 'Unlock badges that time-based rules (loyalty streaks) or edited thresholds now allow.';

    public function handle(BadgeEngine $engine): int
    {
        $unlocked = 0;
        User::query()->where('role', 'customer')
            ->chunkById(200, function ($users) use ($engine, &$unlocked) {
                foreach ($users as $user) {
                    $unlocked += count($engine->evaluate($user));
                }
            });
        if ($this->option('top-referrer')) {
            $unlocked += $engine->awardTopReferrers(now()->subMonthNoOverflow());
        }
        $this->info("Badges unlocked: $unlocked");

        return self::SUCCESS;
    }
}
