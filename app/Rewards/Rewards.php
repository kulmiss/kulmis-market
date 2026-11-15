<?php

namespace App\Rewards;

use App\Fraud\FraudGuard;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\Reward;
use App\Models\User;
use App\Notify\Inbox;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * One way in and out for every new payout: ledger entry, "on the way" for
 * N days (default 14, same as team earnings), held while a fraud check is
 * open, taken back on a refund. Never twice for the same key.
 */
class Rewards
{
    public function __construct(private readonly Ledger $ledger) {}

    public static function account(string $kind): string
    {
        return 'expense:reward_'.$kind;
    }

    public function grant(User $to, string $kind, int $amount, ?Model $source, string $key, string $description, string $notifyTitle): ?Reward
    {
        if ($amount <= 0 || ! $to->isActiveAccount() || ! array_key_exists($kind, Reward::KINDS)) {
            return null;
        }
        if (Reward::query()->withoutGlobalScopes()->where('dedupe_key', $key)->exists()) {
            return null;
        }
        try {
            return DB::transaction(function () use ($to, $kind, $amount, $source, $key, $description, $notifyTitle) {
                $r = Reward::query()->create([
                    'user_id' => $to->id, 'kind' => $kind, 'amount' => $amount, 'status' => Reward::PENDING,
                    'on_hold' => app(FraudGuard::class)->isOnHold($to),
                    'available_at' => now()->addDays(max(0, Settings::int('referral.pending_days'))),
                    'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(),
                    'dedupe_key' => $key, 'description' => mb_substr($description, 0, 255),
                ]);
                $this->ledger->post('reward.earned', [
                    [self::account($kind), $amount],
                    [$this->ledger->userAccount($to, Wallet::PENDING), -$amount],
                ], "reward:{$r->id}:earned", $r, description: $description);
                Inbox::add($to, 'earning', $notifyTitle.': '.Money::format($amount), $description.' It is on the way: ready to use in '
                    .Settings::int('referral.pending_days').' days.', '/account/wallet', "reward:{$r->id}");

                return $r;
            });
        } catch (QueryException) {
            return null; // the same key at the same moment: the other one won
        }
    }

    /** Hourly, with team earnings: on the way → ready to use. */
    public function releaseDue(): int
    {
        $count = 0;
        Reward::query()->withoutGlobalScopes()->where('status', Reward::PENDING)->where('on_hold', false)
            ->where('available_at', '<=', now())->orderBy('id')->each(function (Reward $r) use (&$count) {
                DB::transaction(function () use ($r, &$count) {
                    $locked = Reward::query()->withoutGlobalScopes()->whereKey($r->id)->lockForUpdate()->first();
                    if (! $locked || $locked->status !== Reward::PENDING || $locked->on_hold || $locked->outstanding() <= 0) {
                        return;
                    }
                    if (app(FraudGuard::class)->isOnHold($locked->user_id)) {
                        $locked->update(['on_hold' => true]);

                        return;
                    }
                    $amount = $locked->outstanding();
                    $this->ledger->post('reward.released', [
                        [$this->ledger->userAccount($locked->user_id, Wallet::PENDING), $amount],
                        [$this->ledger->userAccount($locked->user_id, Wallet::AVAILABLE), -$amount],
                    ], "reward:{$locked->id}:released", $locked, description: Reward::KINDS[$locked->kind].' ready to use');
                    $locked->update(['status' => Reward::AVAILABLE, 'released_at' => now()]);
                    $count++;
                });
            });

        return $count;
    }

    /** A refund takes back rewards paid from that money (all of it, or the same share). */
    public function reverseFor(Model $source, int $refunded, int $paid, string $refKey): int
    {
        $count = 0;
        Reward::query()->withoutGlobalScopes()->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())
            ->whereIn('status', [Reward::PENDING, Reward::AVAILABLE])->each(function (Reward $r) use ($refunded, $paid, $refKey, &$count) {
                DB::transaction(function () use ($r, $refunded, $paid, $refKey, &$count) {
                    $locked = Reward::query()->withoutGlobalScopes()->whereKey($r->id)->lockForUpdate()->firstOrFail();
                    $remaining = $locked->outstanding();
                    $take = $refunded >= $paid ? $remaining : min($remaining, intdiv($locked->amount * $refunded, max(1, $paid)));
                    if ($take <= 0) {
                        return;
                    }
                    $bucket = $locked->status === Reward::PENDING ? Wallet::PENDING : Wallet::AVAILABLE;
                    $this->ledger->post('reward.reversed', [
                        [$this->ledger->userAccount($locked->user_id, $bucket), $take],
                        [self::account($locked->kind), -$take],
                    ], "reward:{$locked->id}:refund:$refKey", $locked, 'Refund', description: Reward::KINDS[$locked->kind].' taken back (money given back)');
                    $total = $locked->reversed_amount + $take;
                    $locked->update(['reversed_amount' => $total, 'status' => $total >= $locked->amount ? Reward::REVERSED : $locked->status]);
                    $count++;
                });
            });

        return $count;
    }

    /** Clearing a fraud flag lets held rewards continue. */
    public static function releaseHolds(int $userId): void
    {
        Reward::query()->withoutGlobalScopes()->where('user_id', $userId)->where('on_hold', true)->where('status', Reward::PENDING)->update(['on_hold' => false]);
    }

    /** Paid out (on the way + ready, after refunds) for a kind in a period: for reports and the simulation. */
    public static function total(string $kind, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        return (int) Reward::query()->where('kind', $kind)->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<', $to))->sum(DB::raw('amount - reversed_amount'));
    }
}
