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
        public readonly int $bundleDiscount = 0,
        /** @var list<array{bundle_id:int,name:string,sets:int,saving:int}> */
        public readonly array $bundles = [],
        /** @var list<array{key:string,label:string,amount:int}> paid checkout extras */
        public readonly array $extras = [],
        public readonly bool $couponCapped = false, // the coupon was limited so the order doesn't lose money
        public readonly int $courierCost = 0,        // what the courier charges the shop
        public readonly bool $shippingCapped = false, // free delivery was limited so the order doesn't lose money
    ) {}

    public function extrasTotal(): int
    {
        return (int) array_sum(array_column($this->extras, 'amount'));
    }

    /** Goods after member discount, bundle saving and coupon (commission base for products). */
    public function merchandiseTotal(): int
    {
        return $this->listSubtotal - $this->memberDiscount - $this->bundleDiscount - $this->couponDiscount;
    }

    public function shippingPayable(): int
    {
        return $this->shipping - $this->shippingDiscount;
    }

    public function total(): int
    {
        return $this->merchandiseTotal() + $this->shippingPayable() + $this->extrasTotal();
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
                'regular_unit_price' => $l->regularUnitPrice, 'flash_sale' => $l->flashSaleId !== null,
            ], $this->lines),
            'list_subtotal' => $this->listSubtotal,
            'member_discount' => $this->memberDiscount,
            'bundle_discount' => $this->bundleDiscount,
            'bundles' => $this->bundles,
            'coupon' => $this->coupon?->code,
            'coupon_discount' => $this->couponDiscount,
            'coupon_capped' => $this->couponCapped,
            'shipping_capped' => $this->shippingCapped,
            'merchandise_total' => $this->merchandiseTotal(),
            'shipping' => $this->shipping,
            'shipping_discount' => $this->shippingDiscount,
            'shipping_payable' => $this->shippingPayable(),
            'extras' => $this->extras,
            'extras_total' => $this->extrasTotal(),
            'total' => $this->total(),
            'weight_grams' => $this->weightGrams(),
            'member_savings_preview' => $this->memberSavingsPreview,
            'currency' => Currency::code(),
        ];
    }
}
