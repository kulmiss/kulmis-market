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
    public function quote(array $items, bool $member, ?string $couponCode = null, int $shipping = 0, ?Closure $customerUses = null, bool $checkStock = true, array $extras = [], int $courier = 0): Quote
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
        $merchandise = $afterBundles - $couponDiscount;
        $shippingDiscount = $member && $this->freeShippingApplies($merchandise) ? $shipping : 0;

        // Safety: an order never loses money (product cost, most team earnings, payment fee and
        // what the courier charges). First the coupon is made smaller, then free delivery.
        $capped = false;
        if ($couponDiscount > 0) {
            $allowed = max(0, $afterBundles - $this->lossFloor($lines, $shipping - $shippingDiscount, $courier));
            if ($couponDiscount > $allowed) {
                [$couponDiscount, $capped] = [$allowed, true];
                $merchandise = $afterBundles - $couponDiscount;
                $shippingDiscount = $member && $this->freeShippingApplies($merchandise) ? $shipping : 0;
            }
        }
        $shippingCapped = false;
        if ($shippingDiscount > 0) {
            $charge = min($shippingDiscount, $this->deliveryToCharge($lines, $merchandise, $courier));
            if ($charge > 0) {
                [$shippingDiscount, $shippingCapped] = [$shippingDiscount - $charge, true];
            }
        }

        $preview = 0;
        if (! $member && Features::enabled('membership')) {
            $memberSub = array_sum(array_map(fn (Line $l) => $l->memberUnitPrice * $l->quantity, $lines));
            $preview = $listSubtotal - $memberSub;
            if ($this->freeShippingApplies($memberSub - $couponDiscount)) {
                $preview += $shipping;
            }
        }

        return new Quote($lines, $member, $listSubtotal, $memberDiscount, $couponDiscount, $coupon, $shipping, $shippingDiscount, $preview,
            $bundles['discount'], $bundles['applied'], Extras::price($extras, $merchandise), $capped, $courier, $shippingCapped);
    }

    /**
     * Smallest goods total (after discounts) at which this order still covers the
     * product cost, the most team earnings (all levels), the payment fee and the
     * courier, given what the customer pays for delivery. 0 when the switch is off.
     *
     * @param  list<Line>  $lines
     */
    public function lossFloor(array $lines, int $deliveryCharged = 0, int $courier = 0): int
    {
        if (Features::disabled('shop.coupon_floor')) {
            return 0;
        }
        [$cost, $keepGoods, $keepDelivery] = $this->margins($lines);
        $need = $cost + $courier - intdiv($deliveryCharged * $keepDelivery, 10_000);
        if ($need <= 0) {
            return 0;
        }

        return intdiv($need * 10_000 + $keepGoods - 1, $keepGoods) + 4 * count($lines); // rounded up, plus rounding slack
    }

    /**
     * How much of the delivery fee the customer must still pay so the order covers
     * everything (0 = free delivery is fine). 0 when the switch is off.
     *
     * @param  list<Line>  $lines
     */
    public function deliveryToCharge(array $lines, int $goods, int $courier): int
    {
        if (Features::disabled('shop.coupon_floor')) {
            return 0;
        }
        [$cost, $keepGoods, $keepDelivery] = $this->margins($lines);
        $short = $cost + $courier - intdiv($goods * $keepGoods, 10_000) + 4 * count($lines);
        if ($short <= 0) {
            return 0;
        }

        return intdiv($short * 10_000 + $keepDelivery - 1, $keepDelivery);
    }

    /**
     * @param  list<Line>  $lines
     * @return array{0:int, 1:int, 2:int} product cost, share of the goods kept (bps), share of delivery kept (bps)
     */
    private function margins(array $lines): array
    {
        $cost = 0;
        $bps = 0;
        $profit = app(ProfitCheck::class);
        foreach ($lines as $l) {
            $cost += $l->product->totalCost() * $l->quantity;
            $bps = max($bps, $profit->maxProductCommissionBps($l->product));
        }
        $fee = Settings::int('payments.fee_bps');

        return [$cost, 10_000 - min(9_000, $bps + $fee), 10_000 - min(9_000, $fee)];
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
