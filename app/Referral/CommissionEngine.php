<?php

namespace App\Referral;

use App\Fraud\FraudGuard;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Membership\Members;
use App\Models\Commission;
use App\Models\MembershipPurchase;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SignalPurchase;
use App\Models\User;
use App\Support\Activity;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 3-level unilevel commissions.
 *
 *  type        base (amount actually paid, after discounts, no shipping)   levels
 *  membership  membership month price                                       L1 10%, L2 5%, L3 2%
 *  signals     guest signals month                                          L1 10%
 *  products    order goods total                                            L1 6%,  L2 3%, L3 1%
 *
 * Only ACTIVE members earn. A level whose recipient is not active is
 * skipped (the shop keeps it, it is NOT passed up). Earnings are pending for
 * 14 days, then available. Refunds reverse them.
 */
class CommissionEngine
{
    public const MAX_LEVELS = ['membership' => 3, 'signals' => 1, 'products' => 3];

    public function __construct(private readonly Ledger $ledger, private readonly Attribution $attribution) {}

    /** @return list<Commission> */
    public function forPayment(Payment $payment): array
    {
        $source = $payment->payable;
        [$type, $base, $buyer, $start, $phone, $email] = $this->describe($source);
        if ($type === null || $base <= 0 || $start === null || Features::disabled('referral') || Features::disabled("referral.commission.$type")) {
            return [];
        }

        $created = [];
        $recipient = $start;
        for ($level = 1; $level <= self::MAX_LEVELS[$type] && $recipient; $level++) {
            $next = $recipient->referrer_id ? User::query()->find($recipient->referrer_id) : null;
            if (Features::enabled("referral.level$level")) {
                $created[] = $this->createOne($source, $payment, $type, $level, $base, $recipient, $buyer, $phone, $email);
            }
            $recipient = $next;
        }

        return $created;
    }

    /** Hourly: pending → available after the refund window (unless on hold). */
    public function releaseDue(): int
    {
        $count = 0;
        Commission::query()->where('status', Commission::PENDING)->where('on_hold', false)
            ->where('available_at', '<=', now())->orderBy('id')
            ->each(function (Commission $c) use (&$count) {
                DB::transaction(function () use ($c, &$count) {
                    $locked = Commission::query()->whereKey($c->id)->lockForUpdate()->first();
                    if (! $locked || $locked->status !== Commission::PENDING || $locked->on_hold || $locked->outstanding() <= 0) {
                        return;
                    }
                    if (app(FraudGuard::class)->isOnHold($locked->recipient_id)) {
                        $locked->update(['on_hold' => true]);

                        return;
                    }
                    $amount = $locked->outstanding();
                    $this->ledger->post('commission.released', [
                        [$this->ledger->userAccount($locked->recipient_id, Wallet::PENDING), $amount],
                        [$this->ledger->userAccount($locked->recipient_id, Wallet::AVAILABLE), -$amount],
                    ], "commission:{$locked->id}:released", $locked, description: 'Team earnings ready to use');
                    $locked->update(['status' => Commission::AVAILABLE, 'released_at' => now()]);
                    $count++;
                });
            });

        return $count;
    }

    /** Reverse commissions for a refund (full or proportional). */
    public function reverseForRefund(Payment $payment, int $refundAmount, int $refundId): int
    {
        $source = $payment->payable;
        $paidTotal = $this->paidTotal($source, $payment);
        $full = $source instanceof Order ? $source->fresh()->refunded_amount >= $source->total
            : ($source instanceof MembershipPurchase ? $payment->refunded_amount >= $payment->amount && $source->fresh()->status === 'refunded'
            : $payment->refunded_amount >= $payment->amount);
        $count = 0;
        Commission::query()->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())
            ->whereIn('status', [Commission::PENDING, Commission::AVAILABLE])
            ->each(function (Commission $c) use ($paidTotal, $refundAmount, $full, $refundId, &$count) {
                DB::transaction(function () use ($c, $paidTotal, $refundAmount, $full, $refundId, &$count) {
                    $locked = Commission::query()->whereKey($c->id)->lockForUpdate()->firstOrFail();
                    $remaining = $locked->outstanding();
                    $reverse = $full ? $remaining : min($remaining, intdiv($locked->amount * $refundAmount, max(1, $paidTotal)));
                    if ($reverse <= 0) {
                        return;
                    }
                    $bucket = $locked->status === Commission::PENDING ? Wallet::PENDING : Wallet::AVAILABLE;
                    $this->ledger->post('commission.reversed', [
                        [$this->ledger->userAccount($locked->recipient_id, $bucket), $reverse],
                        [Account::COMMISSIONS, -$reverse],
                    ], "commission:{$locked->id}:refund:$refundId", $locked, 'Refund', description: 'Team earnings taken back (money given back)');
                    $total = $locked->reversed_amount + $reverse;
                    $locked->update(['reversed_amount' => $total, 'status' => $total >= $locked->amount ? Commission::REVERSED : $locked->status]);
                    $count++;
                });
            });

        return $count;
    }

    /**
     * @return array{0:?string,1:int,2:?User,3:?User,4:?string,5:?string} type, base, buyer, level-1 recipient, buyer phone, email
     */
    private function describe(?Model $source): array
    {
        if ($source instanceof Order) {
            $buyer = $source->user_id ? User::query()->find($source->user_id) : null;
            $start = $buyer ? ($buyer->referrer_id ? User::query()->find($buyer->referrer_id) : null)
                : ($source->referrer_user_id ? User::query()->find($source->referrer_user_id) : null);

            return ['products', $source->merchandise_total, $buyer, $start, $source->phone, $source->email];
        }
        if ($source instanceof MembershipPurchase) {
            $buyer = $source->user()->first();

            return ['membership', $source->amount, $buyer, $buyer?->referrer()->first(), $buyer?->phone, $buyer?->email];
        }
        if ($source instanceof SignalPurchase) {
            $sub = $source->subscription()->first();
            $buyer = $sub?->user_id ? User::query()->find($sub->user_id) : null;
            $start = $buyer ? $buyer->referrer()->first() : ($sub?->referrer_user_id ? User::query()->find($sub->referrer_user_id) : null);

            return ['signals', $source->amount, $buyer, $start, $sub?->phone, $sub?->email];
        }

        return [null, 0, null, null, null, null];
    }

    private function createOne(Model $source, Payment $payment, string $type, int $level, int $base, User $recipient, ?User $buyer, ?string $phone, ?string $email): Commission
    {
        $existing = Commission::query()->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->where('level', $level)->first();
        if ($existing) {
            return $existing; // idempotent: never twice for the same sale
        }
        $rate = Settings::int("referral.rate.$type.l$level");
        $amount = Money::bpsFloor($base, $rate);
        $guard = app(FraudGuard::class);
        $device = $source instanceof Order ? $source->device_hash : null;
        $ownAccount = $guard->paidWithOwnAccount($recipient, $payment->payer_account);
        $ownDevice = $buyer === null && $guard->sameDevice($recipient, $device);
        if ($ownAccount || $ownDevice) {
            $guard->flag($recipient, $ownAccount ? 'self_referral_payment' : 'self_referral_device', ['payment' => $payment->id]);
        }
        $skip = match (true) {
            $this->attribution->isSamePerson($recipient, $buyer, $phone, $email), $ownAccount, $ownDevice => 'self_referral',
            ! $recipient->isActiveAccount() => 'account_suspended',
            ! Members::isActive($recipient) => 'not_active_member',
            $amount <= 0 => 'zero_amount',
            default => null,
        };

        $hold = $guard->isOnHold($recipient);

        return DB::transaction(function () use ($source, $payment, $type, $level, $base, $rate, $amount, $recipient, $buyer, $skip, $hold) {
            $c = Commission::query()->create([
                'source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'payment_id' => $payment->id,
                'recipient_id' => $recipient->id, 'buyer_user_id' => $buyer?->id, 'level' => $level, 'type' => $type,
                'base_amount' => $base, 'rate_bps' => $rate, 'amount' => $skip ? 0 : $amount,
                'status' => $skip ? Commission::SKIPPED : Commission::PENDING, 'skip_reason' => $skip,
                'available_at' => $skip ? null : now()->addDays(Settings::int('referral.pending_days')),
                'on_hold' => ! $skip && $hold,
            ]);
            if (! $skip) {
                $this->ledger->post('commission.earned', [
                    [Account::COMMISSIONS, $amount],
                    [$this->ledger->userAccount($recipient, Wallet::PENDING), -$amount],
                ], "commission:{$c->id}:earned", $c, description: "Team earnings, level $level, from ".match ($type) {
                    'membership' => 'a membership', 'products' => 'a shop order', 'signals' => 'signals', default => $type
                });
            } elseif ($skip === 'self_referral') {
                Activity::log('referral.self_referral_skipped', $c, ['level' => $level]);
            }

            return $c;
        });
    }

    private function paidTotal(Model $source, Payment $payment): int
    {
        if ($source instanceof Order) {
            return max(1, $source->total);
        }
        if ($source instanceof MembershipPurchase) {
            return max(1, $source->amount);
        }

        return max(1, $payment->amount);
    }
}
