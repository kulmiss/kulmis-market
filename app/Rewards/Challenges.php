<?php

namespace App\Rewards;

use App\Fraud\FraudGuard;
use App\Membership\Members;
use App\Models\Challenge;
use App\Models\User;
use App\Support\Activity;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Team challenges: "Bring N new paying members this month → X".
 * Counted per calendar month, paid after the month + the refund window,
 * only for still-paid money, within the challenge's monthly cap and the
 * challenge budget (a share of that month's membership money). Over the cap,
 * everyone who made it gets the same smaller amount.
 */
class Challenges
{
    public function __construct(private readonly Rewards $rewards) {}

    public static function monthStart(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->timezone((string) config('app.timezone'))->startOfMonth();
    }

    /** @return list<array{challenge:Challenge, count:int, percent:int, done:bool}> this month's progress for one member */
    public function progress(User $u): array
    {
        if (Features::disabled('referral') || Features::disabled('referral.challenges')) {
            return [];
        }
        $from = self::monthStart();
        $count = Scores::newPayingMembers($from, $from->copy()->addMonthNoOverflow(), $u->id)[$u->id] ?? 0;

        return Challenge::query()->where('is_active', true)->orderBy('target')->get()->map(fn (Challenge $c) => [
            'challenge' => $c, 'count' => $count, 'percent' => (int) min(100, floor($count * 100 / max(1, $c->target))), 'done' => $count >= $c->target,
        ])->all();
    }

    /** Daily: settle every month that ended more than the refund window ago (last 3 months, never twice). */
    public function settleDue(): int
    {
        if (Features::disabled('referral') || Features::disabled('referral.challenges')) {
            return 0;
        }
        $n = 0;
        for ($back = 3; $back >= 1; $back--) {
            $month = self::monthStart()->subMonthsNoOverflow($back);
            if (now()->gte($month->copy()->addMonthNoOverflow()->addDays(Settings::int('referral.pending_days')))) {
                // Only months the challenge was running in.
                foreach (Challenge::query()->where('is_active', true)->where('created_at', '<', $month->copy()->addMonthNoOverflow())->get() as $c) {
                    $n += $this->settle($c, $month) ? 1 : 0;
                }
            }
        }

        return $n;
    }

    public function settle(Challenge $c, Carbon $month): bool
    {
        $from = self::monthStart($month);
        $to = $from->copy()->addMonthNoOverflow();

        return DB::transaction(function () use ($c, $from, $to) {
            $inserted = DB::table('challenge_runs')->insertOrIgnore(['challenge_id' => $c->id, 'month' => $from->toDateString(),
                'created_at' => now(), 'updated_at' => now()]);
            if ($inserted === 0) {
                return false; // already done
            }
            $guard = app(FraudGuard::class);
            $winners = [];
            foreach (Scores::newPayingMembers($from, $to) as $id => $count) {
                if ($count < $c->target) {
                    break; // sorted, biggest first
                }
                $u = User::query()->with('membership')->find($id);
                if ($u && $u->isActiveAccount() && Members::wasActiveAt($u, $to->copy()->subSecond()) && ! $guard->isOnHold($u)) {
                    $winners[] = $u;
                }
            }
            $budget = intdiv(Scores::membershipMoney($from, $to) * Settings::int('challenge.budget'), 10_000);
            $already = (int) DB::table('challenge_runs')->where('month', $from->toDateString())->sum('paid');
            $cap = max(0, min($c->monthly_cap ?? PHP_INT_MAX, $budget - $already));
            $wanted = count($winners) * $c->reward;
            $each = $wanted > $cap ? intdiv($cap, max(1, count($winners))) : $c->reward;
            $paid = 0;
            foreach ($winners as $u) {
                if ($each > 0 && $this->rewards->grant($u, 'challenge', $each, $c, "challenge:{$c->id}:{$u->id}:".$from->format('Y-m'),
                    "Team challenge “{$c->name}” (".$from->format('F Y').')', 'Challenge reward', 0)) {
                    $paid += $each;
                }
            }
            DB::table('challenge_runs')->where('challenge_id', $c->id)->where('month', $from->toDateString())
                ->update(['members' => count($winners), 'paid' => $paid, 'was_cut' => $wanted > $cap, 'updated_at' => now()]);
            Activity::log('challenge.paid', $c, ['month' => $from->format('Y-m'), 'members' => count($winners), 'paid' => Money::format($paid)]);

            return true;
        });
    }
}
