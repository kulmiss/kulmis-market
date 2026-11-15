<?php

namespace App\Badges;

use App\Models\Badge;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the shop in profit on every membership.
 *
 * Worst case paid out of one membership's money (in basis points, 100 = 1%):
 *   team earnings  = membership commission level 1 + 2 + 3
 *   badge bonuses  = the highest "bonus ÷ (badge threshold × price)", never more
 *                    than the per-member team limit, never more than the
 *                    monthly budget (one-time bonuses count against the budget)
 *   loyalty reward = reward ÷ (months × price)
 * The Super Admin can't save numbers that would pay out more than MAX_BPS.
 */
final class ProfitGuard
{
    public const MAX_BPS = 3_500;

    /** @return array{price:int, commissions:int, bonus:int, loyalty:int, total:int, keep:int, max:int} */
    public static function worstCase(): array
    {
        $price = Settings::int('membership.price');
        $commissions = Settings::int('referral.rate.membership.l1') + Settings::int('referral.rate.membership.l2')
            + Settings::int('referral.rate.membership.l3');

        $budget = Settings::int('bonus.budget');
        $badges = Badge::query()->where('is_active', true)->get(['rule', 'threshold', 'monthly_bonus', 'one_time_bonus']);
        $monthly = 0;
        foreach ($badges as $b) {
            if ($b->rule === 'active_level1' && $b->monthly_bonus > 0) {
                $monthly = max($monthly, self::share($b->monthly_bonus, max(1, $b->threshold) * $price));
            }
        }
        $bonus = $badges->contains(fn (Badge $b) => $b->one_time_bonus > 0)
            ? $budget // one-time bonuses are only held back by the monthly budget
            : min($budget, Settings::int('bonus.team_limit'), $monthly);

        $loyalty = self::share(Settings::int('loyalty.reward'), max(1, Settings::int('loyalty.every_months')) * $price);
        $total = $commissions + $bonus + $loyalty;

        return [
            'price' => $price, 'commissions' => $commissions, 'bonus' => $bonus, 'loyalty' => $loyalty,
            'total' => $total, 'keep' => max(0, $price - intdiv($price * $total + 9_999, 10_000)), 'max' => self::MAX_BPS,
        ];
    }

    /** Throws (and the caller's transaction rolls back) when the numbers are not safe. */
    public static function assertSafe(string $field = 'profit'): void
    {
        $w = self::worstCase();
        if ($w['total'] > self::MAX_BPS) {
            throw ValidationException::withMessages([$field => sprintf(
                'Not saved: with these numbers you could pay out up to %s of every membership (team earnings %s, badge bonus %s, loyalty %s). The most allowed is %s, so you always keep at least %s. Lower one of them.',
                self::pct($w['total']), self::pct($w['commissions']), self::pct($w['bonus']), self::pct($w['loyalty']),
                self::pct(self::MAX_BPS), self::pct(10_000 - self::MAX_BPS),
            )]);
        }
    }

    /** "You keep at least 3,983 ETB of every 5,000 ETB". */
    public static function keepLine(): string
    {
        $w = self::worstCase();

        return 'You keep at least '.Money::format($w['keep']).' of every '.Money::format($w['price']).' membership.';
    }

    public static function pct(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2), '0'), '.').'%';
    }

    /** part ÷ whole in basis points, rounded up (never under-states the payout). */
    private static function share(int $part, int $whole): int
    {
        if ($part <= 0) {
            return 0;
        }
        if ($whole <= 0) {
            return 10_000;
        }

        return intdiv($part * 10_000 + $whole - 1, $whole);
    }
}
