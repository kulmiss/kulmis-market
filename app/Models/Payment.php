<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Payments\Payable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $payable_type
 * @property int $payable_id
 * @property ?int $user_id
 * @property string $provider
 * @property string $tx_ref
 * @property int $amount
 * @property int $fee
 * @property int $refunded_amount
 * @property string $status
 * @property ?string $checkout_url
 * @property ?string $provider_reference
 * @property ?string $payer_account
 * @property ?string $proof_path
 * @property ?string $proof_reference
 * @property ?Carbon $paid_at
 * @property ?array<string, mixed> $meta
 * @property (Model&Payable) $payable
 */
class Payment extends Model
{
    use HidesDemo;

    protected static function demoMorphParent(): ?string
    {
        return 'payable';
    }

    public const BANK = 'bank_transfer';

    public const TELEBIRR = 'telebirr';

    public const WALLET = 'wallet';

    /** A membership started from a gift (the money came in with the gift's own payment). */
    public const GIFT = 'gift';

    /** Paid with a gift card (the card's money came in when the card was bought). */
    public const GIFT_CARD = 'gift_card';

    public const PENDING = 'pending';

    public const AWAITING_REVIEW = 'awaiting_review';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public const REFUNDED = 'refunded';

    public const PARTIALLY_REFUNDED = 'partially_refunded';

    protected $guarded = ['id'];

    protected $hidden = ['meta'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'fee' => 'integer', 'refunded_amount' => 'integer', 'paid_at' => 'datetime',
            'reviewed_at' => 'datetime', 'meta' => 'array'];
    }

    /** "Membership · Sara Tesfaye", "Order KM261116-0001 · Guest Abebe": what was paid for, and by whom, in plain words. */
    public function forLabel(): string
    {
        $p = $this->getRelationValue('payable'); // null when the thing paid for was deleted
        if (! $p instanceof Payable) {
            return 'Payment #'.$this->id;
        }
        $what = match (true) {
            $p instanceof Order => 'Order '.$p->number,
            $p instanceof MembershipPurchase => 'Membership',
            $p instanceof SignalPurchase => 'Signals',
            $p instanceof GiftCard => 'Gift card',
            default => $p->payableTitle(),
        };
        $c = $p->payableCustomer();
        $who = trim($c['first_name'].' '.$c['last_name']);

        return $who !== '' ? "$what · $who" : $what;
    }

    /** @return MorphTo<Model, $this> */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isPaid(): bool
    {
        return in_array($this->status, [self::PAID, self::PARTIALLY_REFUNDED, self::REFUNDED], true);
    }

    public function refundable(): int
    {
        return $this->isPaid() ? $this->amount - $this->refunded_amount : 0;
    }
}
