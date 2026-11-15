<?php

namespace App\Wallet;

use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Payable;
use App\Payments\PaymentService;
use App\Support\Features;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pay (part of) an order or membership month from the wallet's available
 * balance. The wallet part is its own Payment row (provider "wallet"), so
 * refunds and commission reversals work the same way as for transfers.
 */
class WalletPayments
{
    public function __construct(private readonly Ledger $ledger, private readonly PaymentService $payments) {}

    public function enabled(): bool
    {
        return Features::enabled('wallet') && Features::enabled('wallet.spend');
    }

    /**
     * @param  Model&Payable  $payable  must have a wallet_used column
     * @return ?Payment the wallet payment, or null when nothing was used
     */
    public function apply(Model $payable, User $user, ?int $max = null): ?Payment
    {
        if (! $this->enabled() || $payable->isSettled()) {
            return null;
        }

        $result = DB::transaction(function () use ($payable, $user, $max) {
            /** @var Model&Payable $locked */
            $locked = $payable->newQuery()->whereKey($payable->getKey())->lockForUpdate()->firstOrFail();
            $available = Wallet::for($user)->available();
            $amount = min($available, $locked->payableAmount(), $max ?? PHP_INT_MAX);
            if ($amount < 100) { // less than 1 ETB: not worth it
                return null;
            }
            $walletAccount = $this->ledger->userAccount($user, Wallet::AVAILABLE);
            $this->ledger->post('wallet.spend', [
                [$walletAccount, $amount],
                [$locked->payableIncomeAccount(), -$amount],
            ], 'wallet:'.$locked->getMorphClass().':'.$locked->getKey().':'.Str::uuid(), $locked,
                description: 'Paid from wallet: '.$locked->payableTitle(), nonNegative: [$walletAccount]);

            $remaining = $locked->payableAmount() - $amount;
            $payment = Payment::query()->create([
                'uuid' => (string) Str::uuid(), 'payable_type' => $locked->getMorphClass(), 'payable_id' => $locked->getKey(),
                'user_id' => $user->id, 'provider' => Payment::WALLET, 'tx_ref' => 'KMW-'.Str::upper(Str::random(18)),
                'amount' => $amount, 'status' => $remaining > 0 ? Payment::PAID : Payment::PENDING,
                'paid_at' => $remaining > 0 ? now() : null,
            ]);
            $locked->forceFill(['wallet_used' => (int) $locked->getAttribute('wallet_used') + $amount])->save();
            $payable->setRawAttributes($locked->getAttributes(), true);

            return [$payment, $remaining];
        });

        if ($result === null) {
            return null;
        }
        [$payment, $remaining] = $result;
        if ($remaining === 0) {
            // Fully paid from the wallet: give value now (commissions fire as usual).
            $this->payments->markPaid($payment);
        }

        return $payment->fresh();
    }

    /** Give the wallet part back when an unpaid order is cancelled. */
    public function releaseFor(Model $payable, string $reason): void
    {
        Payment::query()->where('payable_type', $payable->getMorphClass())->where('payable_id', $payable->getKey())
            ->where('provider', Payment::WALLET)->whereIn('status', [Payment::PAID, Payment::PARTIALLY_REFUNDED])
            ->each(fn (Payment $p) => $this->payments->refund($p, $p->refundable(), $reason));
    }
}
