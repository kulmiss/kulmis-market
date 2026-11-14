<?php

namespace App\Models;

use App\Membership\MembershipService;
use App\Payments\Payable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * One paid month of membership.
 *
 * @property int $id
 * @property int $user_id
 * @property int $amount
 * @property int $wallet_used
 * @property string $status
 * @property ?Carbon $period_start
 * @property ?Carbon $period_end
 * @property ?Carbon $paid_at
 * @property User $user
 */
class MembershipPurchase extends Model implements Payable
{
    protected $fillable = ['user_id', 'amount', 'wallet_used', 'status', 'period_start', 'period_end', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'wallet_used' => 'integer', 'period_start' => 'datetime', 'period_end' => 'datetime', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function payableAmount(): int
    {
        return max(0, $this->amount - $this->wallet_used);
    }

    public function payableTitle(): string
    {
        return 'Membership (1 month)';
    }

    public function payableIncomeAccount(): string
    {
        return 'income:memberships';
    }

    public function payableCustomer(): array
    {
        return ['first_name' => $this->user->first_name, 'last_name' => $this->user->last_name, 'phone' => $this->user->phone, 'email' => $this->user->email];
    }

    public function payableUserId(): ?int
    {
        return $this->user_id;
    }

    public function isSettled(): bool
    {
        return $this->status !== 'pending';
    }

    public function markPaidFrom(Payment $payment): void
    {
        app(MembershipService::class)->activate($this);
    }

    public function refundedFrom(Payment $payment, int $amount, bool $full): void
    {
        if ($full) {
            app(MembershipService::class)->revoke($this, 'Refunded');
        }
    }
}
