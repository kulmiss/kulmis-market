<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Payments\Payable;
use App\Shop\GiftCards;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A gift card: a fixed amount to spend in the shop. Only a hash of the code
 * is kept; the balance is also in the ledger (liability: gift cards).
 *
 * @property int $id
 * @property ?string $code_hash
 * @property ?string $last4
 * @property int $amount
 * @property int $balance
 * @property string $status
 * @property string $buyer_name
 * @property ?string $buyer_phone
 * @property ?string $buyer_email
 * @property ?string $recipient_name
 * @property ?string $recipient_contact
 * @property ?string $message
 * @property ?Carbon $paid_at
 * @property ?Carbon $expires_at
 */
class GiftCard extends Model implements Payable
{
    use HidesDemo;

    public const ACCOUNT = 'liability:gift_cards';

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance' => 'integer', 'paid_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function usable(): bool
    {
        return $this->status === 'active' && $this->balance > 0 && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function payableAmount(): int
    {
        return $this->status === 'pending' ? $this->amount : 0;
    }

    public function payableTitle(): string
    {
        return 'Gift card '.Money::format($this->amount).' ('.$this->buyer_name.')';
    }

    public function payableIncomeAccount(): string
    {
        return self::ACCOUNT;
    }

    public function payableCustomer(): array
    {
        return ['first_name' => $this->buyer_name, 'last_name' => '', 'phone' => $this->buyer_phone, 'email' => $this->buyer_email];
    }

    public function payableUserId(): ?int
    {
        return null;
    }

    public function isSettled(): bool
    {
        return $this->status !== 'pending';
    }

    public function markPaidFrom(Payment $payment): void
    {
        app(GiftCards::class)->activate($this);
    }

    public function refundedFrom(Payment $payment, int $amount, bool $full): void
    {
        if ($this->balance < $this->amount) {
            throw new \InvalidArgumentException('Part of this gift card was already spent. Refund the orders first, or disable the card.');
        }
        $this->update(['status' => $full ? 'refunded' : $this->status, 'balance' => max(0, $this->balance - $amount)]);
    }
}
