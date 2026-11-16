<?php

namespace App\Pricing;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Orders\Extras;
use App\Shop\Bundles;
use App\Shop\FlashSales;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;
use Closure;

/**
 * The single source of truth for what a customer pays.
 *
 * Order of operations:
 *   guest price (or live flash-sale price) → member discount (15%) → bundle saving
 *   → coupon → + shipping → free shipping
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
        return $variant->price ?? $product->price;
    }

    /**
     * @param  list<array{0: Product, 1: ?ProductVariant, 2: int}>  $items
     * @param  ?Closure(Coupon): int  $customerUses  how often this customer already used the coupon
     * @param  list<string>  $extras  paid checkout extras chosen (gift_wrap, express, insurance)
     */
    public function quote(array $items, bool $member, ?string $couponCode = null, int $shipping = 0, ?Closure $customerUses = null, bool $checkStock = true, array $extras = []): Quote
    {
        if ($items === []) {
            throw new PricingException('Your cart is empty.');
        }
        $lines = [];
        $flash = app(FlashSales::class);
        $saleQty = [];
        foreach ($items as [$product, $variant, $qty]) {
            $this->assertPurchasable($product, $variant, $qty, $member, $checkStock);
            $regular = $this->unitListPrice($product, $variant);
            $list = $regular;
            $saleId = null;
            // Flash sale: server-side price only, and only while pieces are left at that price.
            $sale = $flash->for($product, $variant);
            if ($sale !== null && $sale->price < $regular) {
                $wanted = ($saleQty[$sale->id] ?? 0) + $qty;
                if ($sale->remaining() === null || $sale->remaining() >= $wanted) {
                    $saleQty[$sale->id] = $wanted;
                    $list = $sale->price;
                    $saleId = $sale->id;
                }
            }
            $memberUnit = $product->member_discount === false ? $list : $this->memberPrice($list);
            $lines[] = new Line($product, $variant, $qty, $list, $member ? $memberUnit : $list, $memberUnit,
                $saleId !== null ? $regular : null, $saleId);
        }

        $listSubtotal = array_sum(array_map(fn (Line $l) => $l->listTotal(), $lines));
        $afterMember = array_sum(array_map(fn (Line $l) => $l->total(), $lines));
        $memberDiscount = $listSubtotal - $afterMember;

        $bundles = app(Bundles::class)->apply($lines, $member);
        $afterBundles = $afterMember - $bundles['discount'];

        $coupon = null;
        $couponDiscount = 0;
        if ($couponCode !== null && trim($couponCode) !== '') {
            $coupon = $this->resolveCoupon($couponCode, $member, $afterBundles, $customerUses);
            $couponDiscount = $this->couponDiscount($coupon, $afterBundles);
        }
        // A coupon never takes the order below what it costs you (cost + most team earnings + payment fee).
        $capped = false;
        if ($couponDiscount > 0) {
            $allowed = max(0, $afterBundles - $this->lossFloor($lines));
            if ($couponDiscount > $allowed) {
                [$couponDiscount, $capped] = [$allowed, true];
            }
        }

        $merchandise = $afterBundles - $couponDiscount;
        $shippingDiscount = $member && $this->freeShippingApplies($merchandise) ? $shipping : 0;

        $preview = 0;
        if (! $member && Features::enabled('membership')) {
            $memberSub = array_sum(array_map(fn (Line $l) => $l->memberUnitPrice * $l->quantity, $lines));
            $preview = $listSubtotal - $memberSub;
            if ($this->freeShippingApplies($memberSub - $couponDiscount)) {
                $preview += $shipping;
            }
        }

        return new Quote($lines, $member, $listSubtotal, $memberDiscount, $couponDiscount, $coupon, $shipping, $shippingDiscount, $preview,
            $bundles['discount'], $bundles['applied'], Extras::price($extras, $merchandise), $capped);
    }

    /**
     * Smallest goods total (after discounts) at which this order still covers
     * the product cost, the most team earnings (all levels) and the payment fee.
     * 0 when no cost is filled in or the switch is off.
     *
     * @param  list<Line>  $lines
     */
    public function lossFloor(array $lines): int
    {
        if (Features::disabled('shop.coupon_floor')) {
            return 0;
        }
        $cost = 0;
        $bps = 0;
        $profit = app(ProfitCheck::class);
        foreach ($lines as $l) {
            $cost += $l->product->totalCost() * $l->quantity;
            $bps = max($bps, $profit->maxProductCommissionBps($l->product));
        }
        if ($cost <= 0) {
            return 0;
        }
        $keep = 10_000 - min(9_000, $bps + Settings::int('payments.fee_bps'));

        return intdiv($cost * 10_000 + $keep - 1, $keep) + 4 * count($lines); // rounded up, plus rounding slack
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
