<?php

namespace App\Payments;

use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Activity;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Every payment goes through here: Chapa, manual transfer with proof, wallet.
 * markPaid() is idempotent and locks the payment row, so webhooks, redirects
 * and admin approvals can race safely: value is given exactly once.
 */
class PaymentService
{
    public function __construct(private readonly ChapaGateway $chapa, private readonly Ledger $ledger) {}

    /** @param Model&Payable $payable */
    public function startChapa(Model $payable, string $returnUrl): Payment
    {
        $this->assertPayable($payable);
        $payment = $this->create($payable, Payment::CHAPA);
        $url = $this->chapa->initialize($payment, $payable->payableCustomer(), $payable->payableTitle(), $returnUrl,
            route('api.payments.chapa.callback'));
        $payment->update(['checkout_url' => $url]);

        return $payment;
    }

    /** @param Model&Payable $payable */
    public function submitProof(Model $payable, string $provider, UploadedFile $proof, ?string $reference, ?string $payerAccount): Payment
    {
        if (! in_array($provider, [Payment::BANK, Payment::TELEBIRR], true)) {
            throw new InvalidArgumentException('Unknown manual payment method.');
        }
        $this->assertPayable($payable);
        $payment = $this->create($payable, $provider);
        $path = $proof->store('payment-proofs/'.now()->format('Y/m'), 'local');
        $payment->update(['status' => Payment::AWAITING_REVIEW, 'proof_path' => $path,
            'proof_reference' => $reference, 'payer_account' => $payerAccount]);
        Activity::log('payment.proof_submitted', $payment, ['provider' => $provider]);

        return $payment;
    }

    /** Re-check with Chapa (called from webhook, callback and the admin "check" button). */
    public function confirmChapa(string $txRef): ?Payment
    {
        $payment = Payment::query()->where('tx_ref', $txRef)->where('provider', Payment::CHAPA)->first();
        if (! $payment || $payment->isPaid()) {
            return $payment;
        }
        $result = $this->chapa->verify($txRef);
        if ($result['status'] === 'success' && $result['currency'] === 'ETB' && $result['amount'] >= $payment->amount) {
            $this->markPaid($payment, $result['charge'] ?: null, $result['reference'], $result['payer'], ['chapa' => $result['raw']]);
        } elseif (in_array($result['status'], ['failed', 'cancelled'], true)) {
            $payment->update(['status' => Payment::FAILED, 'meta' => ['chapa' => $result['raw']]]);
        } elseif ($result['status'] === 'success') {
            // Paid less than expected or wrong currency: never give value automatically.
            $payment->update(['meta' => ['chapa' => $result['raw'], 'mismatch' => true]]);
            Activity::log('payment.amount_mismatch', $payment, ['expected' => $payment->amount, 'got' => $result['amount'], 'currency' => $result['currency']]);
        }

        return $payment->fresh();
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
            $fee ??= $locked->provider === Payment::CHAPA ? Money::bpsRound($locked->amount, Settings::int('payments.fee_bps')) : 0;
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
    public function refund(Payment $payment, int $amount, string $reason, ?int $actorId = null, bool $viaProvider = true): Refund
    {
        $refund = DB::transaction(function () use ($payment, $amount, $reason, $actorId, $viaProvider) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($amount < 1 || $amount > $locked->refundable()) {
                throw new InvalidArgumentException('Refund amount must be between 0.01 and '.Money::format($locked->refundable()).'.');
            }
            $method = 'manual';
            $ref = null;
            if ($viaProvider && $locked->provider === Payment::CHAPA && config('services.chapa.refund_api') && $this->chapa->configured()) {
                $ref = $this->chapa->refund($locked, $amount, $reason);
                $method = 'chapa';
            }
            if ($locked->provider === Payment::WALLET) {
                $method = 'wallet';
            }
            $refund = $locked->refunds()->create(['amount' => $amount, 'reason' => $reason, 'method' => $method,
                'provider_reference' => $ref, 'created_by' => $actorId]);
            $total = $locked->refunded_amount + $amount;
            $full = $total >= $locked->amount;
            $locked->update(['refunded_amount' => $total, 'status' => $full ? Payment::REFUNDED : Payment::PARTIALLY_REFUNDED]);

            /** @var Model&Payable $payable */
            $payable = $locked->payable;
            if ($locked->provider !== Payment::WALLET) {
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
