<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Payments\Payable;
use App\Signals\SignalAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * One paid month of signals for a guest.
 *
 * @property int $id
 * @property int $signal_subscription_id
 * @property int $amount
 * @property string $status
 * @property ?Carbon $period_start
 * @property ?Carbon $period_end
 * @property SignalSubscription $subscription
 */
class SignalPurchase extends Model implements Payable
{
    use HidesDemo;

    protected static function demoOwnerColumns(): array
    {
        return [];
    }

    protected static function demoParents(): array
    {
        return ['signal_subscription_id' => 'signal_subscriptions'];
    }

    protected $fillable = ['signal_subscription_id', 'amount', 'status', 'period_start', 'period_end', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'period_start' => 'datetime', 'period_end' => 'datetime', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<SignalSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SignalSubscription::class, 'signal_subscription_id');
    }

    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function payableAmount(): int
    {
        return $this->amount;
    }

    public function payableTitle(): string
    {
        return 'Trading signals (1 month)';
    }

    public function payableIncomeAccount(): string
    {
        return 'income:signals';
    }

    public function payableCustomer(): array
    {
        $s = $this->subscription;

        return ['first_name' => $s->first_name, 'last_name' => $s->last_name, 'phone' => $s->phone, 'email' => $s->email];
    }

    public function payableUserId(): ?int
    {
        return $this->subscription->user_id;
    }

    public function isSettled(): bool
    {
        return $this->status !== 'pending';
    }

    public function markPaidFrom(Payment $payment): void
    {
        app(SignalAccess::class)->activatePurchase($this);
    }

    public function refundedFrom(Payment $payment, int $amount, bool $full): void
    {
        if ($full) {
            app(SignalAccess::class)->revokePurchase($this);
        }
    }
}
