<?php

namespace App\Referral;

use App\Models\Commission;
use App\Models\User;
use App\Notify\Inbox;
use App\Support\Features;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** "Earn X this month": the member's own target and a progress bar. */
final class Goals
{
    public const MIN = 10_000;          // 100 ETB

    public const MAX = 100_000_000;     // 1,000,000 ETB

    public static function earnedThisMonth(User $user): int
    {
        return (int) Commission::query()->where('recipient_id', $user->id)
            ->whereIn('status', [Commission::PENDING, Commission::AVAILABLE, Commission::REVERSED])
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum(DB::raw('amount - reversed_amount'));
    }

    /** @return ?array{goal:int, earned:int, percent:int, left:int, reached:bool} */
    public static function progress(User $user, ?int $earned = null): ?array
    {
        if (Features::disabled('account.goals') || ! $user->goal_amount) {
            return null;
        }
        $earned ??= self::earnedThisMonth($user);
        $goal = (int) $user->goal_amount;

        return ['goal' => $goal, 'earned' => $earned, 'percent' => (int) min(100, floor($earned * 100 / max(1, $goal))),
            'left' => max(0, $goal - $earned), 'reached' => $earned >= $goal];
    }

    /** After each new earning: one "You reached your goal" per month. */
    public static function check(User $user): void
    {
        $p = self::progress($user);
        if ($p !== null && $p['reached']) {
            Inbox::add($user, 'goal', 'You reached your goal!', 'You earned '.Money::format($p['earned']).' this month. Your goal was '
                .Money::format($p['goal']).'. Well done!', '/account/earnings', 'goal:'.now()->format('Y-m'));
        }
    }
}
