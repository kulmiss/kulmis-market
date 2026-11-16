<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Membership\Gifts;
use App\Payments\Payable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One month of membership, paid by someone for someone else.
 * The money is held (owed) until the receiver uses the gift.
 *
 * @property int $id
 * @property string $code
 * @property int $amount
 * @property string $status
 * @property ?int $giver_user_id
 * @property string $giver_name
 * @property string $giver_phone
 * @property ?string $giver_email
 * @property string $receiver_name
 * @property string $receiver_contact
 * @property ?string $message
 * @property ?int $referrer_user_id
 * @property ?int $redeemed_by
 * @property ?Carbon $paid_at
 * @property ?Carbon $redeemed_at
 * @property ?Carbon $expires_at
 */
class MembershipGift extends Model implements Payable
{
    use HidesDemo;

    public const ACCOUNT = 'liability:gift_memberships';

    protected $guarded = ['id'];

    protected $hidden = ['code'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime', 'redeemed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function payableAmount(): int
    {
        return $this->status === 'pending' ? $this->amount : 0;
    }

    public function payableTitle(): string
    {
        return 'Gift membership for '.$this->receiver_name.' (from '.$this->giver_name.')';
    }

    /** Owed to the receiver until they use it. */
    public function payableIncomeAccount(): string
    {
        return self::ACCOUNT;
    }

    public function payableCustomer(): array
    {
        return ['first_name' => $this->giver_name, 'last_name' => '', 'phone' => $this->giver_phone, 'email' => $this->giver_email];
    }

    public function payableUserId(): ?int
    {
        return $this->giver_user_id;
    }

    public function isSettled(): bool
    {
        return $this->status !== 'pending';
    }

    public function markPaidFrom(Payment $payment): void
    {
        app(Gifts::class)->paid($this);
    }

    public function refundedFrom(Payment $payment, int $amount, bool $full): void
    {
        if ($this->status === 'redeemed') {
            throw new InvalidArgumentException('This gift was already used. Refund the membership payment of the person who used it instead.');
        }
        if ($full) {
            $this->update(['status' => 'refunded']);
        }
    }
}
