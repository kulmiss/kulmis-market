<?php

namespace App\Pricing;

use App\Models\Coupon;
use App\Support\Currency;

/** A full price breakdown. Every amount is integer santim. */
final class Quote
{
    /** @param list<Line> $lines */
    public function __construct(
        public readonly array $lines,
        public readonly bool $member,
        public readonly int $listSubtotal,       // at guest prices
        public readonly int $memberDiscount,
        public readonly int $couponDiscount,
        public readonly ?Coupon $coupon,
        public readonly int $shipping,           // before free-shipping
        public readonly int $shippingDiscount,
        public readonly int $memberSavingsPreview, // what a guest would save as a member ("Join & save")
    ) {}

    /** Goods after member discount and coupon (commission base for products). */
    public function merchandiseTotal(): int
    {
        return $this->listSubtotal - $this->memberDiscount - $this->couponDiscount;
    }

    public function shippingPayable(): int
    {
        return $this->shipping - $this->shippingDiscount;
    }

    public function total(): int
    {
        return $this->merchandiseTotal() + $this->shippingPayable();
    }

    public function weightGrams(): int
    {
        return array_sum(array_map(fn (Line $l) => $l->weightGrams(), $this->lines));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'member' => $this->member,
            'lines' => array_map(fn (Line $l) => [
                'product_id' => $l->product->id, 'variant_id' => $l->variant?->id,
                'name' => $l->product->name.($l->variant ? ' — '.$l->variant->name : ''),
                'quantity' => $l->quantity, 'list_unit_price' => $l->listUnitPrice,
                'unit_price' => $l->unitPrice, 'member_unit_price' => $l->memberUnitPrice, 'total' => $l->total(),
            ], $this->lines),
            'list_subtotal' => $this->listSubtotal,
            'member_discount' => $this->memberDiscount,
            'coupon' => $this->coupon?->code,
            'coupon_discount' => $this->couponDiscount,
            'merchandise_total' => $this->merchandiseTotal(),
            'shipping' => $this->shipping,
            'shipping_discount' => $this->shippingDiscount,
            'shipping_payable' => $this->shippingPayable(),
            'total' => $this->total(),
            'weight_grams' => $this->weightGrams(),
            'member_savings_preview' => $this->memberSavingsPreview,
            'currency' => Currency::code(),
        ];
    }
}
