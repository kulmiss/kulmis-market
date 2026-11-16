<?php

namespace App\Rewards;

use App\Badges\BadgeEngine;
use App\Fraud\FraudGuard;
use App\Membership\Members;
use App\Models\Contest;
use App\Models\User;
use App\Support\Activity;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Referral contests. Prizes are fixed and announced as "up to": they are
 * paid only after the contest ends AND the refund window passes, counting
 * only money that was really paid and not refunded, skipping anyone with an
 * open fraud check. If the prizes are more than the contest budget
 * (a share of the membership money paid during the contest), everyone's
 * prize is cut by the same share.
 */
class Contests
{
    public function __construct(private readonly Rewards $rewards) {}

    /** @return array<int, int> user id => score (eligible people only), best first */
    public function scores(Contest $c): array
    {
        $raw = $c->rule === 'shop_sales'
            ? Scores::shopSales($c->starts_at, $c->ends_at)
            : Scores::newPayingMembers($c->starts_at, $c->ends_at);
        $raw = array_filter($raw, fn ($s) => $s >= max(1, $c->min_score));
        if ($raw === []) {
            return [];
        }
        $users = User::query()->with('membership')->whereKey(array_keys($raw))->get()->keyBy('id');
        $out = [];
        foreach ($raw as $id => $score) {
            $u = $users->get($id);
            if ($u && $u->isActiveAccount() && Members::wasActiveAt($u, min(now(), $c->ends_at))) {
                $out[$id] = $score;
            }
        }

        return $out;
    }

    /**
     * Live leaderboard: place, first name, badge, score. Cached 5 minutes.
     *
     * @return list<array{place:int, name:string, badge:?array{name:string,icon:string,color:string}, score:int, score_text:string, user_id:int}>
     */
    public function leaderboard(Contest $c, int $limit = 10): array
    {
        return Cache::remember("contest:{$c->id}:board:$limit", 300, function () use ($c, $limit) {
            $badges = app(BadgeEngine::class);
            $out = [];
            $place = 0;
            foreach (array_slice($this->scores($c), 0, $limit, true) as $id => $score) {
                $u = User::query()->find($id);
                if (! $u) {
                    continue;
                }
                $b = $badges->highest($u);
                $out[] = ['place' => ++$place, 'name' => $u->first_name, 'user_id' => $u->id, 'score' => $score,
                    'score_text' => $c->rule === 'shop_sales' ? Money::format($score) : $score.' new '.($score === 1 ? 'member' : 'members'),
                    'badge' => $b ? ['name' => $b->name, 'icon' => $b->icon, 'color' => $b->color] : null];
            }

            return $out;
        });
    }

    /** @return array{place:?int, score:int} */
    public function mine(Contest $c, User $u): array
    {
        $scores = $this->scores($c);
        $place = array_search($u->id, array_keys($scores), true);

        return ['place' => $place === false ? null : $place + 1, 'score' => $scores[$u->id] ?? 0];
    }

    /** Most that can be paid: the contest budget × membership money paid during the contest. */
    public function cap(Contest $c): int
    {
        return intdiv(Scores::membershipMoney($c->starts_at, $c->ends_at) * Settings::int('contest.budget'), 10_000);
    }

    /** Daily: pay every contest whose end + refund window has passed. */
    public function settleDue(): int
    {
        if (Features::disabled('referral') || Features::disabled('referral.contests')) {
            return 0;
        }
        $n = 0;
        foreach (Contest::query()->where('status', 'active')->where('ends_at', '<=', now()->subDays(Settings::int('referral.pending_days')))->get() as $c) {
            $n += $this->settle($c) ? 1 : 0;
        }

        return $n;
    }

    public function settle(Contest $contest): bool
    {
        return DB::transaction(function () use ($contest) {
            $c = Contest::query()->whereKey($contest->id)->lockForUpdate()->firstOrFail();
            if ($c->status !== 'active' || now()->lt($c->settlesAt())) {
                return false;
            }
            $guard = app(FraudGuard::class);
            $winners = [];
            $skipped = [];
            foreach ($this->scores($c) as $id => $score) {
                if (count($winners) >= count($c->prizes)) {
                    break;
                }
                if ($guard->isOnHold($id)) {
                    $skipped[] = $id; // fraud checks first: no prize while a check is open

                    continue;
                }
                $winners[$id] = $score;
            }
            $wanted = 0;
            $i = 0;
            foreach ($winners as $_) {
                $wanted += (int) ($c->prizes[$i++] ?? 0);
            }
            $cap = $this->cap($c);
            $cut = $wanted > $cap;
            $result = [];
            $paid = 0;
            $i = 0;
            foreach ($winners as $id => $score) {
                $prize = (int) ($c->prizes[$i] ?? 0);
                $amount = $cut ? intdiv($prize * $cap, max(1, $wanted)) : $prize;
                $u = User::query()->findOrFail($id);
                $place = $i + 1;
                if ($amount > 0) {
                    $this->rewards->grant($u, 'contest', $amount, $c, "contest:{$c->id}:{$id}", "Contest “{$c->name}”: place $place", 'Contest prize', 0);
                    $paid += $amount;
                }
                $result[] = ['place' => $place, 'name' => $u->first_name, 'score' => $score, 'prize' => $amount];
                $i++;
            }
            $c->forceFill(['status' => 'paid', 'paid_total' => $paid, 'was_cut' => $cut, 'paid_at' => now(), 'result' => $result])->save();
            Activity::log('contest.paid', $c, ['paid' => $paid, 'cap' => $cap, 'cut' => $cut, 'skipped_for_fraud_check' => $skipped]);
            Cache::forget("contest:{$c->id}:board:10");

            return true;
        });
    }
}
