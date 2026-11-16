<?php

namespace App\Badges;

use App\Models\Badge;
use App\Models\MembershipPurchase;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out the badge bonuses for one finished month. Pure maths on real
 * records, nothing is paid here (see BonusRun).
 *
 * The rules:
 * - Only members who paid for EVERY day of the month (no grace days, no
 *   refunded month) and whose account is active get a bonus.
 * - Monthly: the member's highest level-1 team badge at month end, counting
 *   level-1 members whose membership was paid at the last second of the month.
 *   Badges can go down. Highest badge only.
 * - One-time: other badges unlocked in that month, if they have a one-time
 *   bonus and the member was paid up at month end.
 * - Limit 1, per member: the monthly bonus is never more than bonus.team_limit
 *   (2%) of the membership money their level-1 members really paid that month.
 * - Limit 2, the shop: all bonuses together are never more than bonus.budget
 *   (5%) of the month's membership money. Over it, everyone is cut by the
 *   same share ("Bonus adjusted this month").
 * Demo members are worked out apart from real ones, with demo money only.
 */
final class BonusCalculator
{
    public const MONTHLY = 'monthly';

    public const ONE_TIME = 'one_time';

    /**
     * Pools are keyed by is_demo (0 real, 1 demo). $asOf: a forecast for the
     * running month, as if it ended at that moment.
     *
     * @return array{month:string, from:Carbon, to:Carbon, pools: array<int, array{revenue:int, budget:int, wanted:int, paid:int, adjusted:bool, lines: list<array<string, mixed>>}>}
     */
    public function plan(Carbon $month, ?int $onlyUserId = null, ?Carbon $asOf = null): array
    {
        [$from, $to] = self::bounds($month);
        if ($asOf !== null && $asOf->lt($to)) {
            $to = $asOf->copy()->max($from->copy()->addSecond()); // forecast: "if the month ended now"
        }
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();
        $lastSecond = $toTs - 1;

        $coverage = $this->coverage($from, $to, $onlyUserId);
        $members = User::query()->where('status', 'active')
            ->when($onlyUserId !== null, fn ($q) => $q->whereKey($onlyUserId), fn ($q) => $q->whereIn('id', array_keys($coverage)))
            ->get(['id', 'first_name', 'last_name', 'is_demo'])
            ->filter(fn (User $u) => self::covers($coverage[$u->id] ?? [], $fromTs, $toTs))
            ->keyBy('id');

        // Level-1 team of every eligible member.
        $team = $members->isEmpty() ? collect() : User::query()->whereIn('referrer_id', $members->keys())->get(['id', 'referrer_id']);
        $teamCoverage = $onlyUserId !== null && $team->isNotEmpty() ? $this->coverage($from, $to, null, $team->pluck('id')->all()) : $coverage;
        $activeAtEnd = $team->filter(fn (User $u) => self::coversAt($teamCoverage[$u->id] ?? [], $lastSecond))->countBy('referrer_id');

        $money = $this->moneyByBuyer($from, $to, $onlyUserId !== null ? $team->pluck('id')->all() : null);
        $teamMoney = [];
        foreach ($team as $u) {
            $teamMoney[$u->referrer_id] = ($teamMoney[$u->referrer_id] ?? 0) + ($money[$u->id]['net'] ?? 0);
        }

        $monthlyBadges = Badge::query()->where('is_active', true)->where('rule', 'active_level1')->where('monthly_bonus', '>', 0)
            ->orderByDesc('threshold')->get();
        $oneTime = $this->oneTimeUnlocks($from, $to, $members->keys()->all());
        $teamLimit = Settings::int('bonus.team_limit');

        $pools = [];
        foreach ($members as $m) {
            $demo = (int) (bool) $m->getAttribute('is_demo');
            $count = (int) ($activeAtEnd[$m->id] ?? 0);
            $tMoney = (int) ($teamMoney[$m->id] ?? 0);
            $badge = $monthlyBadges->first(fn (Badge $b) => $count >= $b->threshold);
            if ($badge) {
                $limit = intdiv($tMoney * $teamLimit, 10_000);
                $after = min($badge->monthly_bonus, $limit);
                $pools[$demo]['lines'][] = $this->line($m, $badge, self::MONTHLY, $badge->monthly_bonus, $after, $tMoney, $count,
                    $after < $badge->monthly_bonus ? 'team' : null);
            }
            if (self::coversAt($coverage[$m->id] ?? [], $lastSecond)) {
                foreach ($oneTime[$m->id] ?? [] as $b) {
                    $pools[$demo]['lines'][] = $this->line($m, $b, self::ONE_TIME, $b->one_time_bonus, $b->one_time_bonus, $tMoney, $count, null);
                }
            }
        }

        // The shop-wide budget, per pool, from that pool's own membership money.
        // One member only (re-check before a bonus is ready): no budget here,
        // the caller applies the share the month's run used.
        $revenue = [0 => 0, 1 => 0];
        foreach ($onlyUserId === null ? $money : [] as $row) {
            $revenue[(int) $row['demo']] += $row['net'];
        }
        $budgetBps = $onlyUserId === null ? Settings::int('bonus.budget') : 10_000;
        $out = [];
        foreach ([0, 1] as $demo) {
            $lines = $pools[$demo]['lines'] ?? [];
            if ($lines === [] && $revenue[$demo] === 0) {
                continue;
            }
            $budget = $onlyUserId === null ? intdiv($revenue[$demo] * $budgetBps, 10_000) : PHP_INT_MAX;
            $wanted = array_sum(array_column($lines, 'after_team'));
            $adjusted = $wanted > $budget;
            foreach ($lines as &$l) {
                $l['amount'] = $adjusted ? intdiv($l['after_team'] * $budget, max(1, $wanted)) : $l['after_team'];
                if ($adjusted && $l['amount'] < $l['after_team']) {
                    $l['limited_by'] ??= 'budget';
                }
            }
            unset($l);
            $out[$demo] = ['revenue' => $revenue[$demo], 'budget' => $budget, 'wanted' => $wanted,
                'paid' => array_sum(array_column($lines, 'amount')), 'adjusted' => $adjusted, 'lines' => $lines];
        }

        return ['month' => $from->format('Y-m'), 'from' => $from, 'to' => $to, 'pools' => $out];
    }

    /** Level-1 members whose membership is paid at this moment (no grace). */
    public function activeLevel1At(User $user, Carbon $at): int
    {
        $ids = User::query()->where('referrer_id', $user->id)->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }
        $coverage = $this->coverage($at->copy()->subSecond(), $at->copy()->addSecond(), null, $ids);

        return count(array_filter($ids, fn (int $id) => self::coversAt($coverage[$id] ?? [], $at->getTimestamp())));
    }

    /** True when every second from $from to $to was paid. */
    public function paidThrough(User $user, Carbon $from, Carbon $to): bool
    {
        return self::covers($this->coverage($from, $to, $user->id)[$user->id] ?? [], $from->getTimestamp(), $to->getTimestamp());
    }

    /** @return array{0: Carbon, 1: Carbon} first second of the month … first second of the next one (shop time zone) */
    public static function bounds(Carbon $month): array
    {
        $from = $month->copy()->timezone(config('app.timezone'))->startOfMonth();

        return [$from, $from->copy()->addMonthNoOverflow()];
    }

    /** @param list<array{0:int,1:int}> $intervals  true when [from, to) has no unpaid second */
    public static function covers(array $intervals, int $from, int $to): bool
    {
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $cursor = $from;
        foreach ($intervals as [$start, $end]) {
            if ($end <= $cursor) {
                continue;
            }
            if ($start > $cursor) {
                return false;
            }
            $cursor = $end;
            if ($cursor >= $to) {
                return true;
            }
        }

        return $cursor >= $to;
    }

    /** @param list<array{0:int,1:int}> $intervals */
    public static function coversAt(array $intervals, int $at): bool
    {
        foreach ($intervals as [$start, $end]) {
            if ($start <= $at && $at < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Paid membership time per user: a month counts from the moment it was
     * paid (paying late in the grace days does not cover those days).
     *
     * @param  list<int>|null  $userIds
     * @return array<int, list<array{0:int,1:int}>>
     */
    private function coverage(Carbon $from, Carbon $to, ?int $onlyUserId = null, ?array $userIds = null): array
    {
        $out = [];
        MembershipPurchase::query()->where('status', 'paid')->whereNotNull('paid_at')
            ->where('period_end', '>', $from)->where('period_start', '<', $to)->where('paid_at', '<', $to)
            ->when($onlyUserId !== null, fn ($q) => $q->where('user_id', $onlyUserId))
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->toBase()->orderBy('id')->get(['user_id', 'period_start', 'period_end', 'paid_at'])
            ->each(function ($p) use (&$out) {
                $start = max(self::ts($p->period_start), self::ts($p->paid_at));
                $end = self::ts($p->period_end);
                if ($end > $start) {
                    $out[(int) $p->user_id][] = [$start, $end];
                }
            });

        return $out;
    }

    /**
     * Membership money paid in the month, per buyer, after refunds.
     *
     * @param  list<int>|null  $buyerIds
     * @return array<int, array{net:int, demo:bool}>
     */
    private function moneyByBuyer(Carbon $from, Carbon $to, ?array $buyerIds): array
    {
        if ($buyerIds === []) {
            return [];
        }
        $refunds = DB::table('payments')->where('payable_type', (new MembershipPurchase)->getMorphClass())
            ->groupBy('payable_id')->selectRaw('payable_id, SUM(refunded_amount) as refunded');
        $rows = DB::table('membership_purchases as mp')
            ->leftJoinSub($refunds, 'r', 'r.payable_id', '=', 'mp.id')
            ->whereIn('mp.status', ['paid', 'refunded'])->where('mp.paid_at', '>=', $from)->where('mp.paid_at', '<', $to)
            ->when($buyerIds !== null, fn ($q) => $q->whereIn('mp.user_id', $buyerIds))
            ->groupBy('mp.user_id', 'mp.is_demo')
            // Per purchase: a refunded month is no money; a part refund lowers it.
            ->selectRaw("mp.user_id, mp.is_demo, SUM(CASE WHEN mp.status = 'refunded' THEN 0 WHEN mp.amount > COALESCE(r.refunded, 0) THEN mp.amount - COALESCE(r.refunded, 0) ELSE 0 END) as net")
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->user_id] = ['net' => ($out[(int) $r->user_id]['net'] ?? 0) + (int) $r->net, 'demo' => (bool) $r->is_demo];
        }

        return $out;
    }

    /**
     * @param  list<int>  $memberIds
     * @return array<int, list<Badge>>
     */
    private function oneTimeUnlocks(Carbon $from, Carbon $to, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }
        $badges = Badge::query()->where('is_active', true)->where('one_time_bonus', '>', 0)->where('rule', '!=', 'active_level1')->get()->keyBy('id');
        if ($badges->isEmpty()) {
            return [];
        }
        $out = [];
        DB::table('user_badges')->whereIn('user_id', $memberIds)->whereIn('badge_id', $badges->keys())
            ->where('unlocked_at', '>=', $from)->where('unlocked_at', '<', $to)->get(['user_id', 'badge_id'])
            ->each(function ($ub) use (&$out, $badges) {
                $out[(int) $ub->user_id][] = $badges[(int) $ub->badge_id];
            });

        return $out;
    }

    /** @return array<string, mixed> */
    private function line(User $m, Badge $b, string $kind, int $wanted, int $afterTeam, int $teamMoney, int $teamActive, ?string $limitedBy): array
    {
        return ['user_id' => $m->id, 'name' => trim($m->first_name.' '.$m->last_name), 'badge_id' => $b->id, 'badge' => $b->name,
            'kind' => $kind, 'wanted' => $wanted, 'after_team' => $afterTeam, 'amount' => $afterTeam, 'team_money' => $teamMoney,
            'team_active' => $teamActive, 'limited_by' => $limitedBy];
    }

    private static function ts(mixed $value): int
    {
        return Carbon::parse((string) $value, config('app.timezone'))->getTimestamp();
    }

    /** @return Collection<int, Badge> the monthly badges, lowest first */
    public static function ladder(): Collection
    {
        return Badge::query()->where('is_active', true)->where('rule', 'active_level1')->where('monthly_bonus', '>', 0)
            ->orderBy('threshold')->get();
    }
}
