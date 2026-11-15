<?php

namespace App\Pricing;

use App\Models\Bundle;
use App\Models\Product;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;

/**
 * Worst-case profit of one unit sold to a member:
 *   member price − maximum product commission (all enabled levels)
 *   − payment fee − cost.
 */
class ProfitCheck
{
    public function __construct(private readonly PriceCalculator $prices) {}

    public function maxProductCommissionBps(): int
    {
        if (Features::disabled('referral') || Features::disabled('referral.commission.products')) {
            return 0;
        }
        $bps = 0;
        foreach ([1, 2, 3] as $level) {
            if (Features::enabled("referral.level$level")) {
                $bps += Settings::int("referral.rate.products.l$level");
            }
        }

        return $bps;
    }

    /** @return array{price:int, member_price:int, commission:int, payment_fee:int, cost:int, profit:int} */
    public function worstCase(Product $product, ?int $price = null): array
    {
        $price ??= $product->price;
        $memberPrice = Features::enabled('membership') ? $this->prices->memberPrice($price) : $price;
        $commission = Money::bpsRound($memberPrice, min(10_000, $this->maxProductCommissionBps()));
        $fee = Money::bpsRound($memberPrice, Settings::int('payments.fee_bps'));
        $cost = $product->totalCost();

        return ['price' => $price, 'member_price' => $memberPrice, 'commission' => $commission,
            'payment_fee' => $fee, 'cost' => $cost, 'profit' => $memberPrice - $commission - $fee - $cost];
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
     * @return array{price:int, member_price:int, commission:int, payment_fee:int, cost:int, profit:int}
     */
    public function bundle(Bundle $bundle): array
    {
        $price = $bundle->price;
        $memberPrice = Features::enabled('membership') ? $this->prices->memberPrice($price) : $price;
        $commission = Money::bpsRound($memberPrice, min(10_000, $this->maxProductCommissionBps()));
        $fee = Money::bpsRound($memberPrice, Settings::int('payments.fee_bps'));
        $cost = $bundle->cost();

        return ['price' => $price, 'member_price' => $memberPrice, 'commission' => $commission,
            'payment_fee' => $fee, 'cost' => $cost, 'profit' => $memberPrice - $commission - $fee - $cost];
    }

    /** Easy-English line for the admin: "You keep X per member order" or "Loses X per member order". */
    public static function line(int $profit): string
    {
        return $profit >= 0
            ? 'You keep at least '.Money::format($profit).' per member order (after the member price, team earnings, payment fee and cost).'
            : 'This loses '.Money::format(-$profit).' on every member order (after the member price, team earnings, payment fee and cost).';
    }
}
