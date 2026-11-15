<?php

namespace App\Payments;

use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Activity;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Every payment goes through here: bank / Telebirr transfer with a receipt
 * (checked by staff) and the member wallet. markPaid() is idempotent and
 * locks the payment row, so double clicks and two admins approving at the
 * same time give value exactly once.
 */
class PaymentService
{
    public function __construct(private readonly Ledger $ledger) {}

    /** @param Model&Payable $payable */
    public function submitProof(Model $payable, string $provider, UploadedFile $proof, ?string $reference, ?string $payerAccount): Payment
    {
        if (! in_array($provider, [Payment::BANK, Payment::TELEBIRR], true)) {
            throw new InvalidArgumentException('Unknown manual payment method.');
        }
        $this->assertPayable($payable);
        $payment = $this->create($payable, $provider);
        $path = app(ProofStorage::class)->store($proof);
        $payment->update(['status' => Payment::AWAITING_REVIEW, 'proof_path' => $path,
            'proof_reference' => $reference, 'payer_account' => $payerAccount]);
        Activity::log('payment.proof_submitted', $payment, ['provider' => $provider]);

        return $payment;
    }

    public function approveProof(Payment $payment, int $actorId, ?string $note = null): Payment
    {
        if ($payment->status !== Payment::AWAITING_REVIEW) {
            throw new InvalidArgumentException('This payment is not waiting for review.');
        }
        $payment->update(['reviewed_by' => $actorId, 'reviewed_at' => now()]);
        $this->markPaid($payment, 0, $payment->proof_reference, $payment->payer_account, null, $actorId, $note);

        return $payment->fresh();
    }

    public function rejectProof(Payment $payment, int $actorId, string $reason): Payment
    {
        if ($payment->status !== Payment::AWAITING_REVIEW) {
            throw new InvalidArgumentException('This payment is not waiting for review.');
        }
        $payment->update(['status' => Payment::REJECTED, 'reviewed_by' => $actorId, 'reviewed_at' => now(), 'reject_reason' => $reason]);
        Activity::log('payment.rejected', $payment, [], $reason, $actorId);

        return $payment;
    }

    /**
     * The single place value is given. Safe to call many times.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function markPaid(Payment $payment, ?int $fee = null, ?string $reference = null, ?string $payer = null, ?array $meta = null, ?int $actorId = null, ?string $note = null): bool
    {
        $didPay = DB::transaction(function () use ($payment, $fee, $reference, $payer, $meta, $actorId, $note) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->isPaid()) {
                return false;
            }
            $fee ??= 0;
            $locked->update([
                'status' => Payment::PAID, 'paid_at' => now(), 'fee' => $fee,
                'provider_reference' => $reference ?? $locked->provider_reference,
                'payer_account' => $payer ?? $locked->payer_account,
                'meta' => array_merge((array) $locked->meta, (array) $meta),
            ]);

            /** @var Model&Payable $payable */
            $payable = $locked->payable;
            if ($locked->provider !== Payment::WALLET) {
                $lines = [[Account::CASH, $locked->amount], [$payable->payableIncomeAccount(), -$locked->amount]];
                $this->ledger->post('payment.received', $lines, "payment:{$locked->id}:received", $locked,
                    description: $payable->payableTitle());
                if ($fee > 0) {
                    $this->ledger->post('payment.fee', [['expense:payment_fees', $fee], [Account::CASH, -$fee]],
                        "payment:{$locked->id}:fee", $locked, description: 'Payment fee');
                }
            }
            $payable->markPaidFrom($locked);
            Activity::log('payment.paid', $locked, ['provider' => $locked->provider, 'amount' => $locked->amount], $note, $actorId);
            $payment->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($didPay) {
            DB::afterCommit(fn () => PaymentSucceeded::dispatch($payment->fresh()));
        }

        return $didPay;
    }

    /** Full or partial refund. Commission reversal listens to PaymentRefunded. */
    public function refund(Payment $payment, int $amount, string $reason, ?int $actorId = null): Refund
    {
        $refund = DB::transaction(function () use ($payment, $amount, $reason, $actorId) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($amount < 1 || $amount > $locked->refundable()) {
                throw new InvalidArgumentException('Refund amount must be between 0.01 and '.Money::format($locked->refundable()).'.');
            }
            // Transfers are refunded by hand (Finance sends the money back); wallet payments go back to the wallet.
            $method = $locked->provider === Payment::WALLET ? 'wallet' : 'manual';
            $refund = $locked->refunds()->create(['amount' => $amount, 'reason' => $reason, 'method' => $method,
                'provider_reference' => null, 'created_by' => $actorId]);
            $total = $locked->refunded_amount + $amount;
            $full = $total >= $locked->amount;
            $locked->update(['refunded_amount' => $total, 'status' => $full ? Payment::REFUNDED : Payment::PARTIALLY_REFUNDED]);

            /** @var Model&Payable $payable */
            $payable = $locked->payable;
            if ($locked->provider === Payment::WALLET) {
                // Back to the member's available balance.
                $this->ledger->post('wallet.refunded', [[$payable->payableIncomeAccount(), $amount],
                    [$this->ledger->userAccount((int) $locked->user_id, Wallet::AVAILABLE), -$amount]],
                    "refund:{$refund->id}", $locked, $reason, $actorId, 'Refund to wallet');
            } else {
                $this->ledger->post('payment.refunded', [[$payable->payableIncomeAccount(), $amount], [Account::CASH, -$amount]],
                    "refund:{$refund->id}", $locked, $reason, $actorId, 'Refund');
            }
            $payable->refundedFrom($locked, $amount, $full);
            Activity::log('payment.refunded', $locked, ['amount' => $amount, 'method' => $method], $reason, $actorId);
            $payment->setRawAttributes($locked->getAttributes(), true);

            return $refund;
        });

        DB::afterCommit(fn () => PaymentRefunded::dispatch($payment->fresh(), $refund));

        return $refund;
    }

    /** @param Model&Payable $payable */
    private function create(Model $payable, string $provider): Payment
    {
        return Payment::query()->create([
            'uuid' => (string) Str::uuid(),
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'user_id' => $payable->payableUserId(),
            'provider' => $provider,
            'tx_ref' => 'KM-'.Str::upper(Str::random(20)),
            'amount' => $payable->payableAmount(),
            'currency' => Currency::code(),
            'status' => Payment::PENDING,
        ]);
    }

    /** @param Model&Payable $payable */
    private function assertPayable(Model $payable): void
    {
        if ($payable->isSettled() || $payable->payableAmount() < 100) {
            throw new InvalidArgumentException('Nothing to pay.');
        }
    }
}
