<?php

namespace App\Membership;

use App\Events\MembershipEnded;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\Membership;
use App\Models\MembershipPurchase;
use App\Models\SignalPurchase;
use App\Models\SignalSubscription;
use App\Signals\SignalAccess;
use App\Support\Activity;
use App\Support\Features;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * People who already paid for a feature that is switched off keep it until
 * their paid month ends. The Super Admin can see who that is before switching
 * off, and can end it at once ("End access now"), with the unused days added
 * to their wallet if wanted (worked out from what each paid month cost).
 */
final class PaidAccess
{
    /** Features people pay for by the month. */
    public const FEATURES = ['membership', 'signals'];

    public function __construct(private readonly Ledger $ledger, private readonly SignalAccess $signals) {}

    /**
     * Who still has paid access now.
     *
     * @return list<array{id:int, user_id:?int, name:string, until:string, unused:int}> unused = santim for the days left
     */
    public function people(string $feature): array
    {
        $this->check($feature);
        $now = now();
        $rows = [];
        if ($feature === 'membership') {
            foreach (Membership::query()->with('user')->whereIn('status', ['active', 'grace'])->where('current_period_end', '>', $now)->get() as $m) {
                $rows[] = ['id' => $m->id, 'user_id' => $m->user_id, 'name' => trim($m->user->first_name.' '.mb_substr((string) $m->user->last_name, 0, 1)),
                    'until' => $m->current_period_end?->toIso8601String() ?? '', 'unused' => $this->unusedMembership($m->user_id, $now)];
            }
        } else {
            foreach (SignalSubscription::query()->where('source', SignalSubscription::PURCHASE)->where('status', 'active')
                ->where('current_period_end', '>', $now)->get() as $s) {
                $rows[] = ['id' => $s->id, 'user_id' => $s->user_id, 'name' => trim($s->first_name.' '.mb_substr((string) $s->last_name, 0, 1)),
                    'until' => $s->current_period_end?->toIso8601String() ?? '', 'unused' => $this->unusedSignals($s, $now)];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['until'], $b['until']));

        return $rows;
    }

    /** @return array{feature:string, people:int, last_until:?string, unused_total:int, can_credit:bool} */
    public function summary(string $feature): array
    {
        $rows = $this->people($feature);

        return [
            'feature' => $feature, 'people' => count($rows), 'last_until' => $rows === [] ? null : end($rows)['until'],
            'unused_total' => array_sum(array_column($rows, 'unused')),
            'can_credit' => Features::enabled('wallet') && $this->creditable($rows) > 0,
        ];
    }

    /**
     * End the paid access of everyone now (only while the feature is off).
     *
     * @return array{ended:int, credited:int, credit_total:int}
     */
    public function endNow(string $feature, bool $credit, int $actorId): array
    {
        $this->check($feature);
        if (Features::enabled($feature)) {
            throw new InvalidArgumentException('Switch the feature off first.');
        }
        if ($credit && Features::disabled('wallet')) {
            throw new InvalidArgumentException('The wallet is switched off, so nothing can be added to wallets.');
        }
        $stats = ['ended' => 0, 'credited' => 0, 'credit_total' => 0];
        foreach ($this->people($feature) as $row) {
            DB::transaction(function () use ($feature, $row, $credit, $actorId, &$stats) {
                $ended = $feature === 'membership' ? $this->endMembership($row['id'], $actorId) : $this->endSignals($row['id'], $actorId);
                if (! $ended) {
                    return;
                }
                $stats['ended']++;
                if ($credit && $row['user_id'] && $row['unused'] > 0) {
                    $this->ledger->post('access.unused_credit', [
                        [Account::UNUSED_ACCESS, $row['unused']],
                        [$this->ledger->userAccount((int) $row['user_id'], Wallet::AVAILABLE), -$row['unused']],
                    ], "unused:$feature:{$row['user_id']}:".now()->timestamp, null, 'Paid days not used ('.$feature.' switched off)', $actorId,
                        description: 'Money back for days not used');
                    $stats['credited']++;
                    $stats['credit_total'] += $row['unused'];
                }
            });
        }
        Activity::log('feature.access_ended', null, ['feature' => $feature] + $stats, 'End access now', $actorId);

        return $stats;
    }

    private function endMembership(int $id, int $actorId): bool
    {
        $m = Membership::query()->whereKey($id)->lockForUpdate()->first();
        if (! $m || ! in_array($m->status, ['active', 'grace'], true)) {
            return false;
        }
        $m->update(['status' => 'expired', 'current_period_end' => now(), 'grace_ends_at' => now(), 'continuous_since' => null]);
        Activity::log('membership.ended_now', $m, [], 'Membership switched off: End access now', $actorId);
        DB::afterCommit(fn () => MembershipEnded::dispatch($m, 'Membership switched off'));

        return true;
    }

    private function endSignals(int $id, int $actorId): bool
    {
        $sub = SignalSubscription::query()->whereKey($id)->where('status', 'active')->lockForUpdate()->first();
        if (! $sub) {
            return false;
        }
        $sub->update(['current_period_end' => now(), 'grace_ends_at' => now()->subSecond()]);
        DB::afterCommit(fn () => $this->signals->remove($sub->fresh() ?? $sub, 'Signals switched off: End access now', $actorId));

        return true;
    }

    /** Santim for the paid days after $now (each paid month counted at what it cost). */
    private function unusedMembership(int $userId, Carbon $now): int
    {
        return $this->unused(MembershipPurchase::query()->where('user_id', $userId)->where('status', 'paid')->where('period_end', '>', $now)->get()->all(), $now);
    }

    private function unusedSignals(SignalSubscription $s, Carbon $now): int
    {
        return $this->unused(SignalPurchase::query()->where('signal_subscription_id', $s->id)->where('status', 'paid')->where('period_end', '>', $now)->get()->all(), $now);
    }

    /** @param list<MembershipPurchase|SignalPurchase> $purchases */
    private function unused(array $purchases, Carbon $now): int
    {
        $total = 0;
        foreach ($purchases as $p) {
            if (! $p->period_start || ! $p->period_end) {
                continue;
            }
            $length = max(1, $p->period_end->getTimestamp() - $p->period_start->getTimestamp());
            $left = $p->period_end->getTimestamp() - max($now->getTimestamp(), $p->period_start->getTimestamp());
            $total += intdiv($p->amount * max(0, min($left, $length)), $length);
        }

        return $total;
    }

    /** @param list<array{id:int, user_id:?int, name:string, until:string, unused:int}> $rows */
    private function creditable(array $rows): int
    {
        return count(array_filter($rows, fn ($r) => $r['user_id'] && $r['unused'] > 0));
    }

    private function check(string $feature): void
    {
        if (! in_array($feature, self::FEATURES, true)) {
            throw new InvalidArgumentException('Unknown paid feature.');
        }
    }
}
