<?php

namespace App\Membership;

use App\Demo\Demo;
use App\Events\MembershipActivated;
use App\Events\MembershipEnded;
use App\Jobs\SendCustomerMessage;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\Membership;
use App\Models\MembershipPurchase;
use App\Models\User;
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
 * Monthly membership: pay → +1 month. Renewing before access ends keeps the
 * membership "continuous" (for loyalty rewards). After the period there is a
 * grace period (default 3 days) with full benefits, then it expires:
 * discounts, signals and earnings stop; the wallet is kept.
 */
class MembershipService
{
    public function __construct(private readonly Ledger $ledger) {}

    public function newPurchase(User $user): MembershipPurchase
    {
        if (Features::disabled('membership')) {
            throw new InvalidArgumentException('Membership is not available right now.');
        }
        // Reuse an open purchase so repeated clicks don't pile up.
        $open = MembershipPurchase::query()->where('user_id', $user->id)->where('status', 'pending')
            ->where('created_at', '>', now()->subDay())->latest('id')->first();
        $price = Settings::int('membership.price');
        if ($open && $open->amount === $price) {
            return $open;
        }

        return MembershipPurchase::query()->create(['user_id' => $user->id, 'amount' => $price, 'status' => 'pending']);
    }

    public function activate(MembershipPurchase $purchase): Membership
    {
        return DB::transaction(function () use ($purchase) {
            /** @var MembershipPurchase $p */
            $p = MembershipPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($p->status === 'paid') {
                return Membership::query()->where('user_id', $p->user_id)->firstOrFail();
            }
            $now = now();
            $m = Membership::query()->where('user_id', $p->user_id)->lockForUpdate()->first()
                ?? new Membership(['user_id' => $p->user_id, 'periods_paid' => 0]);

            $continuing = $m->exists && $m->isActive($now) && $m->current_period_end !== null;
            $start = $continuing ? $m->current_period_end->copy() : $now->copy();
            $end = $start->copy()->addMonthNoOverflow();
            $m->fill([
                'status' => 'active',
                'current_period_start' => $continuing ? $m->current_period_start : $start,
                'current_period_end' => $end,
                'grace_ends_at' => $end->copy()->addDays(Settings::int('membership.grace_days')),
                'continuous_since' => $continuing ? ($m->continuous_since ?? $start) : $start,
                'periods_paid' => $m->periods_paid + 1,
            ])->save();
            $p->update(['status' => 'paid', 'paid_at' => $now, 'period_start' => $start, 'period_end' => $end]);
            Activity::log('membership.activated', $m, ['until' => $end->toIso8601String(), 'continuing' => $continuing], null, $p->user_id);

            DB::afterCommit(function () use ($m, $p) {
                MembershipActivated::dispatch($m, $p);
                $this->message($p->user, Settings::string('notify.membership_activated'), $m, 'membership_activated');
            });

            return $m;
        });
    }

    /** A refunded month is taken back; if that ends access, the membership ends now. */
    public function revoke(MembershipPurchase $purchase, string $reason, ?int $actorId = null): void
    {
        DB::transaction(function () use ($purchase, $reason, $actorId) {
            $p = MembershipPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== 'paid') {
                return;
            }
            $p->update(['status' => 'refunded']);
            $m = Membership::query()->where('user_id', $p->user_id)->lockForUpdate()->first();
            if (! $m || ! $p->period_start || ! $p->period_end) {
                return;
            }
            $days = (int) $p->period_start->diffInDays($p->period_end);
            $newEnd = $m->current_period_end?->copy()->subDays($days);
            $ended = $newEnd === null || $newEnd->lte(now());
            $m->update([
                'current_period_end' => $ended ? now() : $newEnd,
                'grace_ends_at' => $ended ? now() : $newEnd->copy()->addDays(Settings::int('membership.grace_days')),
                'status' => $ended ? 'expired' : $m->status,
                'periods_paid' => max(0, $m->periods_paid - 1),
                'continuous_since' => $ended ? null : $m->continuous_since,
            ]);
            Activity::log('membership.revoked', $m, ['purchase' => $p->id], $reason, $actorId);
            if ($ended) {
                DB::afterCommit(fn () => MembershipEnded::dispatch($m, $reason));
            }
        });
    }

    /** Admin: extend or end a membership by hand (always logged with a reason). */
    public function adjust(User $user, int $days, string $reason, int $actorId): Membership
    {
        return DB::transaction(function () use ($user, $days, $reason, $actorId) {
            $m = Membership::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? new Membership(['user_id' => $user->id, 'periods_paid' => 0]);
            $base = $m->exists && $m->isActive() && $m->current_period_end ? $m->current_period_end->copy() : now();
            $end = $base->copy()->addDays($days);
            $ended = $end->lte(now());
            $m->fill([
                'status' => $ended ? 'expired' : 'active',
                'current_period_start' => $m->current_period_start ?? now(),
                'current_period_end' => $ended ? now() : $end,
                'grace_ends_at' => $ended ? now() : $end->copy()->addDays(Settings::int('membership.grace_days')),
                'continuous_since' => $ended ? null : ($m->continuous_since ?? now()),
            ])->save();
            Activity::log('membership.adjusted', $m, ['days' => $days], $reason, $actorId);
            if ($ended) {
                DB::afterCommit(fn () => MembershipEnded::dispatch($m, $reason));
            }

            return $m;
        });
    }

    /**
     * Daily: active → grace → expired, plus renewal reminders.
     *
     * @return array<string, int>
     */
    public function sweep(?Carbon $now = null): array
    {
        $now ??= now();
        $stats = ['grace' => 0, 'expired' => 0, 'reminders' => 0];
        if (Features::disabled('membership')) {
            return $stats; // paused: nothing expires and no reminders go out while it's off
        }

        Membership::query()->where('status', 'active')->where('current_period_end', '<', $now)
            ->each(function (Membership $m) use (&$stats) {
                $m->update(['status' => 'grace']);
                $stats['grace']++;
            });
        Membership::query()->whereIn('status', ['active', 'grace'])->where('grace_ends_at', '<', $now)
            ->each(function (Membership $m) use (&$stats) {
                $m->update(['status' => 'expired', 'continuous_since' => null]);
                Activity::log('membership.expired', $m);
                MembershipEnded::dispatch($m, 'Expired');
                $stats['expired']++;
            });

        $before = Settings::int('membership.reminder_days_before');
        $after = Settings::int('membership.reminder_days_after');
        Membership::query()->with('user')->whereIn('status', ['active', 'grace'])->whereNotNull('current_period_end')
            ->each(function (Membership $m) use ($now, $before, $after, &$stats) {
                $end = $m->current_period_end;
                $kind = match (true) {
                    $end->isSameDay($now) => 'today',
                    $end->gt($now) && $end->lte($now->copy()->addDays($before)) => 'before',
                    $end->lt($now) && $end->lte($now->copy()->subDays($after)) => 'after',
                    default => null,
                };
                if ($kind && $this->remindOnce($m, $kind)) {
                    $this->message($m->user, Settings::string('notify.membership_'.$kind), $m, 'membership_'.$kind);
                    $stats['reminders']++;
                }
            });

        return $stats;
    }

    /** Daily: 300 ETB wallet credit after every 3 months of continuous membership. */
    public function grantLoyaltyRewards(?Carbon $now = null): int
    {
        if (Features::disabled('loyalty_reward') || Features::disabled('membership')) {
            return 0;
        }
        $now ??= now();
        $every = max(1, Settings::int('loyalty.every_months'));
        $amount = Settings::int('loyalty.reward');
        $granted = 0;

        Membership::query()->with('user')->whereIn('status', ['active', 'grace'])->whereNotNull('continuous_since')
            ->each(function (Membership $m) use ($now, $every, $amount, &$granted) {
                if (! $m->isActive($now)) {
                    return;
                }
                $months = (int) floor($m->continuous_since->diffInMonths($now));
                $due = intdiv($months, $every);
                for ($seq = 1; $seq <= $due; $seq++) {
                    $exists = DB::table('loyalty_rewards')->where('user_id', $m->user_id)
                        ->where('continuous_since', $m->continuous_since)->where('sequence', $seq)->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::transaction(function () use ($m, $seq, $amount) {
                        $entry = $this->ledger->post('loyalty.reward', [
                            [Account::LOYALTY, $amount],
                            [$this->ledger->userAccount($m->user_id, Wallet::AVAILABLE), -$amount],
                        ], "loyalty:{$m->user_id}:{$m->continuous_since->timestamp}:$seq", $m, description: 'Loyalty reward');
                        DB::table('loyalty_rewards')->insert(['user_id' => $m->user_id, 'is_demo' => Demo::isDemoUser($m->user_id), 'continuous_since' => $m->continuous_since,
                            'sequence' => $seq, 'amount' => $amount, 'journal_entry_id' => $entry->id, 'created_at' => now()]);
                    });
                    $this->message($m->user, str_replace('{amount}', Money::format($amount), Settings::string('notify.loyalty_reward')), $m, 'loyalty_reward');
                    $granted++;
                }
            });

        return $granted;
    }

    private function remindOnce(Membership $m, string $kind): bool
    {
        try {
            DB::table('membership_reminders')->insert(['membership_id' => $m->id, 'period_end' => $m->current_period_end,
                'kind' => $kind, 'created_at' => now()]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    private function message(User $user, string $template, Membership $m, string $type): void
    {
        $body = strtr($template, [
            '{name}' => $user->first_name,
            '{date}' => $m->current_period_end?->timezone(config('app.timezone'))->format('d M Y') ?? '',
            '{price}' => Money::format(Settings::int('membership.price')),
            '{grace_date}' => $m->grace_ends_at?->timezone(config('app.timezone'))->format('d M Y') ?? '',
            '{renew_url}' => url('/membership'),
            '{site}' => Settings::string('site.name'),
        ]);
        SendCustomerMessage::dispatch(new Message('Your membership', $body, $user->phone, $user->email, $user->telegram_user_id, $type));
    }
}
