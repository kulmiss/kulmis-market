<?php

namespace App\Jobs;

use App\Badges\BadgeEngine;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Re-check one member's badges (after a payment in their team, or the daily sweep). */
class EvaluateBadges implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $userId)
    {
        $this->afterCommit();
    }

    public function handle(BadgeEngine $engine): void
    {
        $user = User::query()->find($this->userId);
        if ($user) {
            $engine->evaluate($user);
        }
    }
}
