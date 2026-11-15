<?php

namespace App\Badges;

use App\Models\Badge;
use App\Models\Challenge;
use App\Models\Contest;
use App\Support\Features;
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
 *   first-payment bonus = bonus ÷ price (as if every membership were someone's first)
 *   welcome-back reward = reward ÷ price (as if every membership were a come-back)
 *   contest prizes  = the contest budget (prizes are cut to fit it)
 *   team challenges = the highest "reward ÷ (target × price)", never more than the challenge budget
 * The Super Admin can't save numbers (or turn on a switch) that would pay out more than MAX_BPS.
 */
final class ProfitGuard
{
    public const MAX_BPS = 3_500;

    /** Switches that turn on a payout: turning one on is checked too. */
    public const PAYOUT_SWITCHES = ['referral', 'referral.level1', 'referral.level2', 'referral.level3', 'referral.commission.membership',
        'badges', 'badges.bonus', 'loyalty_reward', 'referral.first_payment_bonus', 'membership.comeback_reward', 'referral.contests', 'referral.challenges'];

    /** Settings whose change is checked. */
    public const PATTERN = '/^(referral\.rate\.membership\.|membership\.price|loyalty\.|bonus\.|reward\.|contest\.|challenge\.)/';

    /** @return array{price:int, commissions:int, bonus:int, loyalty:int, first_payment:int, comeback:int, contests:int, challenges:int, total:int, keep:int, max:int} */
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
        $first = Features::enabled('referral.first_payment_bonus') ? self::share(Settings::int('bonus.first_payment'), $price) : 0;
        $comeback = Features::enabled('membership.comeback_reward') ? self::share(Settings::int('reward.comeback'), $price) : 0;
        $contests = Features::enabled('referral.contests') && Contest::query()->where('status', 'active')->exists()
            ? Settings::int('contest.budget') : 0;
        $challenges = 0;
        if (Features::enabled('referral.challenges')) {
            foreach (Challenge::query()->where('is_active', true)->get(['reward', 'target']) as $c) {
                $challenges += self::share($c->reward, max(1, $c->target) * $price); // one member can make several
            }
            $challenges = min($challenges, Settings::int('challenge.budget'));
        }
        $total = $commissions + $bonus + $loyalty + $first + $comeback + $contests + $challenges;

        return [
            'price' => $price, 'commissions' => $commissions, 'bonus' => $bonus, 'loyalty' => $loyalty,
            'first_payment' => $first, 'comeback' => $comeback, 'contests' => $contests, 'challenges' => $challenges,
            'total' => $total, 'keep' => max(0, $price - intdiv($price * $total + 9_999, 10_000)), 'max' => self::MAX_BPS,
        ];
    }

    /** Throws (and the caller's transaction rolls back) when the numbers are not safe. */
    public static function assertSafe(string $field = 'profit'): void
    {
        $w = self::worstCase();
        if ($w['total'] > self::MAX_BPS) {
            $parts = array_filter(['team earnings' => $w['commissions'], 'badge bonus' => $w['bonus'], 'loyalty' => $w['loyalty'],
                'first-payment bonus' => $w['first_payment'], 'welcome-back reward' => $w['comeback'], 'contest prizes' => $w['contests'],
                'team challenges' => $w['challenges']]);
            throw ValidationException::withMessages([$field => sprintf(
                'Not saved: with these numbers you could pay out up to %s of every membership (%s). The most allowed is %s, so you always keep at least %s. Lower one of them.',
                self::pct($w['total']), implode(', ', array_map(fn ($k, $v) => $k.' '.self::pct($v), array_keys($parts), $parts)),
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
