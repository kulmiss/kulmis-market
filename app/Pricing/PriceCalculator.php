<?php

namespace App\Pricing;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;
use Closure;

/**
 * The single source of truth for what a customer pays.
 *
 * Order of operations:
 *   guest price → member discount (15%) → coupon → + shipping → free shipping
 *   for members when goods ≥ 3,000 ETB.
 */
class PriceCalculator
{
    public function memberDiscountActive(): bool
    {
        return Features::enabled('membership.member_discount');
    }

    public function memberPrice(int $price): int
    {
        if (! $this->memberDiscountActive()) {
            return $price;
        }

        return $price - Money::bpsRound($price, Settings::int('pricing.member_discount_bps'));
    }

    public function unitListPrice(Product $product, ?ProductVariant $variant): int
    {
        return $variant?->price ?? $product->price;
    }

    /**
     * @param  list<array{0: Product, 1: ?ProductVariant, 2: int}>  $items
     * @param  ?Closure(Coupon): int  $customerUses  how often this customer already used the coupon
     */
    public function quote(array $items, bool $member, ?string $couponCode = null, int $shipping = 0, ?Closure $customerUses = null, bool $checkStock = true): Quote
    {
        if ($items === []) {
            throw new PricingException('Your cart is empty.');
        }
        $lines = [];
        foreach ($items as [$product, $variant, $qty]) {
            $this->assertPurchasable($product, $variant, $qty, $member, $checkStock);
            $list = $this->unitListPrice($product, $variant);
            $memberUnit = $this->memberPrice($list);
            $lines[] = new Line($product, $variant, $qty, $list, $member ? $memberUnit : $list, $memberUnit);
        }

        $listSubtotal = array_sum(array_map(fn (Line $l) => $l->listTotal(), $lines));
        $afterMember = array_sum(array_map(fn (Line $l) => $l->total(), $lines));
        $memberDiscount = $listSubtotal - $afterMember;

        $coupon = null;
        $couponDiscount = 0;
        if ($couponCode !== null && trim($couponCode) !== '') {
            $coupon = $this->resolveCoupon($couponCode, $member, $afterMember, $customerUses);
            $couponDiscount = $this->couponDiscount($coupon, $afterMember);
        }

        $merchandise = $afterMember - $couponDiscount;
        $shippingDiscount = $member && $this->freeShippingApplies($merchandise) ? $shipping : 0;

        $preview = 0;
        if (! $member && Features::enabled('membership')) {
            $memberSub = array_sum(array_map(fn (Line $l) => $l->memberUnitPrice * $l->quantity, $lines));
            $preview = $listSubtotal - $memberSub;
            if ($this->freeShippingApplies($memberSub - $couponDiscount)) {
                $preview += $shipping;
            }
        }

        return new Quote($lines, $member, $listSubtotal, $memberDiscount, $couponDiscount, $coupon, $shipping, $shippingDiscount, $preview);
    }

    public function freeShippingApplies(int $merchandiseTotal): bool
    {
        return Features::enabled('shop.free_shipping') && $merchandiseTotal >= Settings::int('shipping.member_free_over');
    }

    private function assertPurchasable(Product $product, ?ProductVariant $variant, int $qty, bool $member, bool $checkStock): void
    {
        if ($qty < 1 || $qty > 99) {
            throw new PricingException('Quantity must be between 1 and 99.');
        }
        if (! $product->isPurchasableBy($member)) {
            throw new PricingException($product->is_members_only && ! $member
                ? "“{$product->name}” is for members only."
                : "“{$product->name}” is not available.");
        }
        $activeVariants = $product->variants->where('is_active', true);
        if ($variant === null && $activeVariants->isNotEmpty()) {
            throw new PricingException("Choose an option for “{$product->name}”.");
        }
        if ($variant !== null && ($variant->product_id !== $product->id || ! $variant->is_active)) {
            throw new PricingException('That option is not available.');
        }
        if ($checkStock && ! $product->isMadeToOrder()) {
            $stock = $variant !== null ? (int) $variant->stock : (int) $product->stock;
            if ($qty > $stock) {
                throw new PricingException($stock > 0 ? "Only $stock left of “{$product->name}”." : "“{$product->name}” is sold out.");
            }
        }
    }

    private function resolveCoupon(string $code, bool $member, int $subtotal, ?Closure $customerUses): Coupon
    {
        if (Features::disabled('shop.coupons')) {
            throw new PricingException('Coupons are not available right now.');
        }
        $coupon = Coupon::findByCode($code);
        $now = now();
        if (! $coupon || ! $coupon->is_active || ($coupon->starts_at && $coupon->starts_at->gt($now)) || ($coupon->ends_at && $coupon->ends_at->lt($now))) {
            throw new PricingException('This coupon is not valid.');
        }
        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            throw new PricingException('This coupon has been used up.');
        }
        if ($coupon->members_only && ! $member) {
            throw new PricingException('This coupon is for members only.');
        }
        if ($subtotal < $coupon->min_subtotal) {
            throw new PricingException('Spend at least '.Money::format($coupon->min_subtotal).' to use this coupon.');
        }
        if ($coupon->max_uses_per_customer !== null && $customerUses && $customerUses($coupon) >= $coupon->max_uses_per_customer) {
            throw new PricingException('You have already used this coupon.');
        }

        return $coupon;
    }

    private function couponDiscount(Coupon $coupon, int $subtotal): int
    {
        $discount = $coupon->type === Coupon::PERCENT
            ? Money::bpsRound($subtotal, min(10_000, $coupon->value))
            : $coupon->value;
        if ($coupon->max_discount !== null) {
            $discount = min($discount, $coupon->max_discount);
        }

        return min($discount, $subtotal);
    }
}
