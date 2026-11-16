<?php

namespace App\Reports;

use App\Models\Badge;
use App\Support\Features;
use App\Support\Settings;

/**
 * "What if" for the owner and "What you could earn" for members, from the
 * real settings: $members people at the top each bring $invites paying
 * members, who each bring $invites more, down 3 levels. One month of
 * memberships (everyone pays every month).
 */
final class WhatIf
{
    /** @return array<string, mixed> money in santim */
    public function forShop(int $members, float $invites): array
    {
        $members = max(0, min($members, 1_000_000));
        $invites = max(0.0, min($invites, 50.0));
        $price = Settings::int('membership.price');
        $rate = fn (int $l) => Features::enabled('membership') && Features::enabled('referral') && Features::enabled("referral.level$l") && Features::enabled('referral.commission.membership')
            ? Settings::int("referral.rate.membership.l$l") : 0;
        $levels = [$members, $members * $invites, $members * $invites ** 2, $members * $invites ** 3];
        $people = (int) round(array_sum($levels));
        $in = $people * $price;
        // Each paying member's money goes up to 3 levels (only people who were invited have an upline).
        $team = (int) round($levels[1] * $price * $rate(1) / 10_000
            + $levels[2] * $price * ($rate(1) + $rate(2)) / 10_000
            + $levels[3] * $price * ($rate(1) + $rate(2) + $rate(3)) / 10_000);
        $bonus = $this->badgeBonus($invites, $price) * (int) round($levels[0] + $levels[1] + $levels[2]);
        $bonus = min($bonus, intdiv($in * Settings::int('bonus.budget'), 10_000));
        $out = $team + $bonus;

        return [
            'members' => $people, 'money_in' => $in, 'team_earnings' => $team, 'badge_bonus' => $bonus, 'paid_to_members' => $out,
            'kept' => $in - $out, 'kept_percent' => $in > 0 ? round(100 * ($in - $out) / $in, 1) : 100.0,
            'one_member_earns' => $this->forMember((int) round($invites), (int) round($invites))['per_month'],
        ];
    }

    /**
     * One member who brings $direct paying members, each of whom brings $theirs.
     *
     * @return array<string, int> santim per month (and the one-time first-payment bonuses)
     */
    public function forMember(int $direct, int $theirs = 0): array
    {
        $direct = max(0, min($direct, 1_000));
        $theirs = max(0, min($theirs, 100));
        $price = Settings::int('membership.price');
        $on = fn (int $l) => Features::enabled('membership') && Features::enabled('referral') && Features::enabled("referral.level$l") && Features::enabled('referral.commission.membership');
        $l1 = $on(1) ? intdiv($price * Settings::int('referral.rate.membership.l1'), 10_000) * $direct : 0;
        $l2 = $on(2) ? intdiv($price * Settings::int('referral.rate.membership.l2'), 10_000) * $direct * $theirs : 0;
        $l3 = $on(3) ? intdiv($price * Settings::int('referral.rate.membership.l3'), 10_000) * $direct * $theirs * $theirs : 0;
        $bonus = $this->badgeBonus((float) $direct, $price);

        return ['level1' => $l1, 'level2' => $l2, 'level3' => $l3, 'badge_bonus' => $bonus, 'per_month' => $l1 + $l2 + $l3 + $bonus,
            'first_payment_bonus' => Features::enabled('referral.first_payment_bonus') ? Settings::int('bonus.first_payment') * $direct : 0];
    }

    /** The monthly badge bonus of someone with this many active level-1 members (with the per-member limit). */
    private function badgeBonus(float $direct, int $price): int
    {
        if (Features::disabled('badges.bonus')) {
            return 0;
        }
        $b = Badge::query()->where('is_active', true)->where('rule', 'active_level1')->where('monthly_bonus', '>', 0)
            ->where('threshold', '<=', (int) floor($direct))->orderByDesc('threshold')->first();

        return $b ? min((int) $b->monthly_bonus, (int) floor($direct * $price * Settings::int('bonus.team_limit') / 10_000)) : 0;
    }
}
