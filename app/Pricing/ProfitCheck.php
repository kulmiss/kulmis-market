<?php

namespace App\Pricing;

use App\Models\Bundle;
use App\Models\Product;
use App\Shipping\ZoneShippingQuoter;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;

/**
 * Worst-case profit of one unit sold to a member:
 *   member price − maximum product commission (all enabled levels)
 *   − payment fee − cost − courier (when that one item already gets free
 *   delivery: the most any city's courier charges).
 */
class ProfitCheck
{
    public function __construct(private readonly PriceCalculator $prices) {}

    public function maxProductCommissionBps(?Product $product = null): int
    {
        if (Features::disabled('referral') || Features::disabled('referral.commission.products')) {
            return 0;
        }
        $type = $product?->isAddon() ? 'addons' : 'products';
        $bps = 0;
        foreach ([1, 2, 3] as $level) {
            if (Features::enabled("referral.level$level")) {
                $bps += Settings::int("referral.rate.$type.l$level");
            }
        }

        return $bps;
    }

    /** @return array{price:int, member_price:int, commission:int, payment_fee:int, cost:int, courier:int, profit:int} */
    public function worstCase(Product $product, ?int $price = null): array
    {
        $price ??= $product->price;
        $memberPrice = Features::enabled('membership') && $product->member_discount !== false ? $this->prices->memberPrice($price) : $price;
        $commission = Money::bpsRound($memberPrice, min(10_000, $this->maxProductCommissionBps($product)));
        $fee = Money::bpsRound($memberPrice, Settings::int('payments.fee_bps'));
        $cost = $product->totalCost();
        $courier = $this->freeDeliveryCourier($memberPrice, $product->isDigital() ? 0 : (int) $product->weight_grams);

        return ['price' => $price, 'member_price' => $memberPrice, 'commission' => $commission,
            'payment_fee' => $fee, 'cost' => $cost, 'courier' => $courier, 'profit' => $memberPrice - $commission - $fee - $cost - $courier];
    }

    /** What the shop pays the courier when one item at this member price already gets free delivery (else 0). */
    private function freeDeliveryCourier(int $memberPrice, int $weightGrams): int
    {
        if ($weightGrams <= 0 || Features::disabled('membership') || ! $this->prices->freeShippingApplies($memberPrice)) {
            return 0;
        }

        return ZoneShippingQuoter::maxCourierCost($weightGrams);
    }

    /** @return list<array{code:string,message:string}> */
    public function warnings(Product $product): array
    {
        $warnings = $product->priceWarnings();
        $prices = array_merge([$product->price], $product->variants->whereNotNull('price')->pluck('price')->all());
        foreach (array_unique($prices) as $price) {
            $w = $this->worstCase($product, (int) $price);
            if ($w['profit'] < 0) {
                $warnings[] = ['code' => 'unprofitable_member_order', 'message' => sprintf(
                    'At %s a member order loses %s after the member price cut, team earnings and the payment fee.',
                    Money::format((int) $price), Money::format(-$w['profit']))];
                break;
            }
        }

        return $warnings;
    }

    /**
     * Worst case for one bundle set sold to a member at the bundle price.
     *
     * @return array{price:int, member_price:int, commission:int, payment_fee:int, cost:int, courier:int, profit:int}
     */
    public function bundle(Bundle $bundle): array
    {
        $price = $bundle->price;
        $memberPrice = Features::enabled('membership') ? $this->prices->memberPrice($price) : $price;
        $commission = Money::bpsRound($memberPrice, min(10_000, $this->maxProductCommissionBps()));
        $fee = Money::bpsRound($memberPrice, Settings::int('payments.fee_bps'));
        $cost = $bundle->cost();
        $bundle->loadMissing('items.product');
        $weight = (int) $bundle->items->sum(fn ($i) => ! $i->product->isDigital() ? (int) $i->product->weight_grams * $i->quantity : 0);
        $courier = $this->freeDeliveryCourier($memberPrice, $weight);

        return ['price' => $price, 'member_price' => $memberPrice, 'commission' => $commission,
            'payment_fee' => $fee, 'cost' => $cost, 'courier' => $courier, 'profit' => $memberPrice - $commission - $fee - $cost - $courier];
    }

    /** Easy-English line for the admin: "You keep X per member order" or "Loses X per member order". */
    public static function line(int $profit): string
    {
        return $profit >= 0
            ? 'You keep at least '.Money::format($profit).' per member order (after the member price, team earnings, payment fee and cost).'
            : 'This loses '.Money::format(-$profit).' on every member order (after the member price, team earnings, payment fee and cost).';
    }
}
