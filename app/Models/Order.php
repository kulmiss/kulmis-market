<?php

namespace App\Models;

use App\Orders\OrderService;
use App\Payments\Payable;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property ?int $user_id
 * @property string $first_name
 * @property string $last_name
 * @property string $phone
 * @property ?string $email
 * @property string $city
 * @property string $address
 * @property ?int $shipping_zone_id
 * @property ?int $courier_id
 * @property ?string $tracking_number
 * @property string $status
 * @property bool $is_member_order
 * @property int $list_subtotal
 * @property int $member_discount
 * @property ?int $coupon_id
 * @property ?string $coupon_code
 * @property int $coupon_discount
 * @property int $merchandise_total
 * @property int $shipping
 * @property int $shipping_discount
 * @property int $total
 * @property int $wallet_used
 * @property int $payment_fee
 * @property int $refunded_amount
 * @property string $checkout_key
 * @property ?string $device_hash
 * @property ?Carbon $paid_at
 * @property ?Carbon $shipped_at
 * @property ?Carbon $delivered_at
 * @property ?Carbon $cancelled_at
 * @property ?string $cancel_reason
 * @property Carbon $created_at
 */
class Order extends Model implements Payable
{
    public const PENDING_PAYMENT = 'pending_payment';

    public const PAID = 'paid';

    public const MAKING = 'making';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    /** Allowed moves for the fulfilment flow. */
    public const TRANSITIONS = [
        self::PENDING_PAYMENT => [self::PAID, self::CANCELLED],
        self::PAID => [self::MAKING, self::SHIPPED, self::CANCELLED, self::REFUNDED],
        self::MAKING => [self::SHIPPED, self::CANCELLED, self::REFUNDED],
        self::SHIPPED => [self::DELIVERED, self::REFUNDED],
        self::DELIVERED => [self::REFUNDED],
        self::CANCELLED => [],
        self::REFUNDED => [],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        $ints = array_fill_keys(['list_subtotal', 'member_discount', 'coupon_discount', 'merchandise_total', 'shipping',
            'shipping_discount', 'total', 'wallet_used', 'payment_fee', 'refunded_amount'], 'integer');

        return $ints + ['is_member_order' => 'boolean', 'paid_at' => 'datetime', 'shipped_at' => 'datetime',
            'delivered_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusChange, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    // --- Payable -----------------------------------------------------------

    public function payableAmount(): int
    {
        return $this->amountDue();
    }

    public function payableTitle(): string
    {
        return 'Order '.$this->number;
    }

    public function payableIncomeAccount(): string
    {
        return 'income:sales';
    }

    public function payableCustomer(): array
    {
        return ['first_name' => $this->first_name, 'last_name' => $this->last_name, 'phone' => $this->phone, 'email' => $this->email];
    }

    public function payableUserId(): ?int
    {
        return $this->user_id;
    }

    public function isSettled(): bool
    {
        return $this->status !== self::PENDING_PAYMENT;
    }

    public function markPaidFrom(Payment $payment): void
    {
        $this->refresh();
        $this->update(['payment_fee' => $this->payment_fee + $payment->fee]);
        if ($this->status === self::CANCELLED) {
            // Paid after auto-cancel: keep the money recorded, flag for a refund or re-open.
            Activity::log('order.paid_after_cancel', $this, ['payment' => $payment->uuid]);

            return;
        }
        app(OrderService::class)->markPaid($this, 'Paid via '.$payment->provider);
    }

    public function refundedFrom(Payment $payment, int $amount, bool $full): void
    {
        $this->refresh();
        $this->update(['refunded_amount' => $this->refunded_amount + $amount]);
        if ($this->refunded_amount >= $this->total && $this->canMoveTo(self::REFUNDED)) {
            app(OrderService::class)->transition($this, self::REFUNDED, 'Refunded');
        }
    }

    public function shippingPayable(): int
    {
        return $this->shipping - $this->shipping_discount;
    }

    /** What still has to be paid by Chapa/bank after the wallet part. */
    public function amountDue(): int
    {
        return max(0, $this->total - $this->wallet_used);
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function customerName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
