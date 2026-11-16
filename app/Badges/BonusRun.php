<?php

namespace App\Badges;

use App\Fraud\FraudGuard;
use App\Jobs\SendCustomerMessage;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\BadgeBonus;
use App\Models\BadgeBonusRun;
use App\Models\User;
use App\Notify\Inbox;
use App\Notify\Message;
use App\Support\Activity;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pays the badge bonuses of a finished month, once.
 *
 * - Safe to run again: one run per month (unique), one bonus per member,
 *   month and badge (unique), one ledger entry per bonus (idempotency key).
 * - The scheduled job (1st of the month) pays by itself only after the
 *   Super Admin has checked the list and paid the very first month by hand.
 * - Bonuses are "on the way" (pending) for bonus.pending_days, then ready.
 *   Just before they become ready they are worked out again: a refund or an
 *   ended membership since then lowers or cancels the bonus. Fraud holds
 *   keep it on the way.
 */
final class BonusRun
{
    public function __construct(private readonly Ledger $ledger, private readonly BonusCalculator $calculator) {}

    /** The month that just ended. */
    public static function lastMonth(): Carbon
    {
        return now()->timezone(config('app.timezone'))->startOfMonth()->subMonthNoOverflow();
    }

    public static function monthOf(string $ym): Carbon
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            throw new InvalidArgumentException('Month must look like 2026-10.');
        }

        return Carbon::createFromFormat('Y-m-d H:i:s', $ym.'-01 00:00:00', config('app.timezone'));
    }

    public function runFor(Carbon $month, bool $demo = false): ?BadgeBonusRun
    {
        return BadgeBonusRun::query()->whereDate('month', BonusCalculator::bounds($month)[0]->toDateString())->where('is_demo', $demo)->first();
    }

    /** True once any month was paid: from then on the 1st of the month pays by itself. */
    public function firstPayoutDone(): bool
    {
        return BadgeBonusRun::query()->where('is_demo', false)->where('status', BadgeBonusRun::PAID)->exists();
    }

    /**
     * The 1st of the month.
     *
     * @return string what happened, in plain words
     */
    public function scheduled(): string
    {
        if (Features::disabled('badges.bonus')) {
            return 'Badge bonuses are switched off.';
        }
        $month = self::lastMonth();
        if ($this->runFor($month)) {
            return 'Already done for '.$month->format('F Y').'.';
        }
        if (! $this->firstPayoutDone()) {
            Activity::log('badge_bonus.waiting', null, ['month' => $month->format('Y-m')]);

            return 'Waiting for the Super Admin to check and pay the first month ('.$month->format('F Y').').';
        }
        $run = $this->pay($month, null);

        return 'Paid '.Money::format($run->paid).' to '.$run->members.' members for '.$month->format('F Y').'.';
    }

    /** Pay a finished month (also "Pay now" in the admin). Returns the real-money run. */
    public function pay(Carbon $month, ?int $actorId): BadgeBonusRun
    {
        if (Features::disabled('badges.bonus')) {
            throw new InvalidArgumentException('Badge bonuses are switched off (Feature switches → Badges).');
        }
        [$from, $to] = BonusCalculator::bounds($month);
        if ($to->gt(now())) {
            throw new InvalidArgumentException('You can only pay a month that has ended. '.$from->format('F Y').' ends on '.$to->copy()->subSecond()->format('d M Y').'.');
        }
        $existing = $this->runFor($month);
        if ($existing) {
            return $existing; // never twice
        }

        $plan = $this->calculator->plan($month);
        $pending = max(0, Settings::int('bonus.pending_days'));
        $notify = [];
        foreach ([0, 1] as $demo) {
            $pool = $plan['pools'][$demo] ?? ['revenue' => 0, 'budget' => 0, 'wanted' => 0, 'paid' => 0, 'adjusted' => false, 'lines' => []];
            if ($demo === 1 && $pool['lines'] === []) {
                continue;
            }
            try {
                DB::transaction(function () use ($pool, $demo, $from, $actorId, $pending, &$notify) {
                    $run = BadgeBonusRun::query()->create([
                        'month' => $from->toDateString(), 'status' => BadgeBonusRun::PAID, 'revenue' => $pool['revenue'],
                        'budget' => $pool['budget'], 'wanted' => $pool['wanted'], 'paid' => 0, 'adjusted' => $pool['adjusted'],
                        'members' => 0, 'actor_id' => $actorId, 'is_demo' => (bool) $demo,
                    ]);
                    $paid = 0;
                    $members = [];
                    foreach ($pool['lines'] as $line) {
                        if ($line['amount'] <= 0) {
                            continue;
                        }
                        $bonus = BadgeBonus::query()->create([
                            'run_id' => $run->id, 'user_id' => $line['user_id'], 'badge_id' => $line['badge_id'], 'month' => $from->toDateString(),
                            'kind' => $line['kind'], 'wanted' => $line['wanted'], 'amount' => $line['amount'], 'limited_by' => $line['limited_by'],
                            'team_money' => $line['team_money'], 'team_active' => $line['team_active'], 'status' => BadgeBonus::PENDING,
                            'available_at' => now()->addDays($pending), 'is_demo' => (bool) $demo,
                        ]);
                        $entry = $this->ledger->post('badge.bonus', [
                            [Account::BADGE_BONUSES, $line['amount']],
                            [$this->ledger->userAccount($line['user_id'], Wallet::PENDING), -$line['amount']],
                        ], "badge-bonus:{$bonus->id}", $bonus, actorId: $actorId,
                            description: 'Badge bonus: '.$line['badge'].' ('.$from->format('F Y').')');
                        $bonus->update(['journal_entry_id' => $entry->id]);
                        $paid += $line['amount'];
                        $members[$line['user_id']] = true;
                        $notify[] = [$bonus->id, $pool['adjusted']];
                    }
                    $run->update(['paid' => $paid, 'members' => count($members)]);
                    Activity::log('badge_bonus.paid', $run, ['month' => $from->format('Y-m'), 'paid' => $paid, 'members' => count($members),
                        'adjusted' => $pool['adjusted'], 'demo' => (bool) $demo], null, $actorId);
                });
            } catch (QueryException $e) {
                if (! $this->runFor($month, (bool) $demo)) {
                    throw $e; // not a "someone else just paid it" race
                }
            }
        }
        DB::afterCommit(fn () => $this->notify($notify));

        return $this->runFor($month) ?? throw new InvalidArgumentException('Nothing was paid.');
    }

    /** "Skip this month": nothing is paid for it, now or later. */
    public function skip(Carbon $month, ?int $actorId, ?string $note = null): BadgeBonusRun
    {
        [$from] = BonusCalculator::bounds($month);
        if ($from->gt(now())) {
            throw new InvalidArgumentException('You can skip this month or a month that has ended, not a later one.');
        }
        if ($run = $this->runFor($month)) {
            if ($run->status === BadgeBonusRun::PAID) {
                throw new InvalidArgumentException($from->format('F Y').' is already paid.');
            }

            return $run;
        }
        foreach ([false, true] as $demo) {
            if (! $this->runFor($month, $demo)) {
                BadgeBonusRun::query()->create(['month' => $from->toDateString(), 'is_demo' => $demo,
                    'status' => BadgeBonusRun::SKIPPED, 'note' => $note, 'actor_id' => $actorId]);
            }
        }
        Activity::log('badge_bonus.skipped', null, ['month' => $from->format('Y-m')], $note, $actorId);

        return $this->runFor($month) ?? throw new InvalidArgumentException('Could not skip.');
    }

    /** Hourly: on the way → ready, after a last check. */
    public function releaseDue(): int
    {
        $count = 0;
        BadgeBonus::query()->where('status', BadgeBonus::PENDING)->where('on_hold', false)->where('available_at', '<=', now())
            ->orderBy('id')->each(function (BadgeBonus $b) use (&$count) {
                DB::transaction(function () use ($b, &$count) {
                    $locked = BadgeBonus::query()->whereKey($b->id)->lockForUpdate()->first();
                    if (! $locked || $locked->status !== BadgeBonus::PENDING || $locked->on_hold) {
                        return;
                    }
                    if (app(FraudGuard::class)->isOnHold($locked->user_id)) {
                        $locked->update(['on_hold' => true]);

                        return;
                    }
                    $allowed = min($locked->outstanding(), $this->stillAllowed($locked));
                    $cut = $locked->outstanding() - $allowed;
                    $pendingAccount = $this->ledger->userAccount($locked->user_id, Wallet::PENDING);
                    if ($cut > 0) {
                        $this->ledger->post('badge.bonus.reduced', [[$pendingAccount, $cut], [Account::BADGE_BONUSES, -$cut]],
                            "badge-bonus:{$locked->id}:reduced", $locked, 'Membership refunded or ended', description: 'Badge bonus taken back (money given back)');
                        $locked->reduced_amount += $cut;
                    }
                    if ($allowed > 0) {
                        $this->ledger->post('badge.bonus.released', [
                            [$pendingAccount, $allowed],
                            [$this->ledger->userAccount($locked->user_id, Wallet::AVAILABLE), -$allowed],
                        ], "badge-bonus:{$locked->id}:released", $locked, description: 'Badge bonus ready to use');
                        $locked->status = BadgeBonus::AVAILABLE;
                        $locked->released_at = now();
                        $count++;
                    } else {
                        $locked->status = BadgeBonus::REDUCED;
                    }
                    $locked->save();
                });
            });

        return $count;
    }

    /** Work the member's bonus out again with today's records (refunds since the run). */
    private function stillAllowed(BadgeBonus $bonus): int
    {
        $run = $bonus->run()->firstOrFail();
        $plan = $this->calculator->plan($bonus->month->copy(), $bonus->user_id);
        $line = collect($plan['pools'][(int) $bonus->is_demo]['lines'] ?? [])
            ->first(fn (array $l) => $l['kind'] === $bonus->kind && ($bonus->kind === BonusCalculator::MONTHLY || $l['badge_id'] === $bonus->badge_id));
        if (! $line) {
            return 0;
        }

        // Never more than what was promised, whatever the numbers are now.
        return $run->allows(min((int) $line['after_team'], $bonus->wanted));
    }

    /** @param list<array{0:int,1:bool}> $items */
    private function notify(array $items): void
    {
        foreach ($items as [$id, $adjusted]) {
            $b = BadgeBonus::query()->with(['user', 'badge'])->find($id);
            if (! $b || ! $b->user) {
                continue;
            }
            $u = $b->user;
            $body = strtr(Settings::string('notify.badge_bonus'), [
                '{name}' => $u->first_name, '{badge}' => $b->badge->name ?? 'badge', '{month}' => $b->month->format('F Y'),
                '{amount}' => Money::format($b->amount), '{ready}' => $b->available_at?->timezone(config('app.timezone'))->format('d M') ?? '',
                '{adjusted}' => $adjusted ? ' Bonus adjusted this month: many members earned a bonus, so everyone got the same smaller share.' : '',
                '{site}' => Settings::string('site.name'),
            ]);
            SendCustomerMessage::dispatch(new Message('Your badge bonus', $body, $u->phone, $u->email, $u->telegram_user_id, 'badge_bonus'));
            Inbox::add($u, 'bonus', 'Badge bonus: '.Money::format($b->amount), $body, '/account/badges#badge-bonus', "bonus:{$b->id}");
        }
    }

    /** Clearing a fraud flag lets held bonuses continue. */
    public static function releaseHolds(int $userId): void
    {
        BadgeBonus::query()->where('user_id', $userId)->where('on_hold', true)->where('status', BadgeBonus::PENDING)->update(['on_hold' => false]);
    }

    /** @return array{0:int, 1:int} [amount for month (ready + on the way), count] */
    public static function totalsFor(User $user): array
    {
        $rows = BadgeBonus::query()->where('user_id', $user->id)->whereIn('status', [BadgeBonus::PENDING, BadgeBonus::AVAILABLE]);

        return [(int) (clone $rows)->sum(DB::raw('amount - reduced_amount')), (clone $rows)->count()];
    }
}
