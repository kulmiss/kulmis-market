<?php

namespace App\Help;

use App\Support\Money;
use App\Support\Settings;

/**
 * Live numbers for help texts: write {membership_price} or {pending_days}
 * instead of typing a number, so the text is always right after a setting
 * changes. Unknown {words} are left as they are.
 */
final class HelpTokens
{
    /** @return array<string, array{0:string, 1:string}> token => [value, what it is] */
    public static function all(): array
    {
        $price = Settings::int('membership.price');
        $rate = fn (string $k) => Settings::int($k);
        $pct = fn (int $bps) => rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.').'%';
        $share = fn (string $k) => Money::format(intdiv($price * $rate($k), 10_000));
        $l1 = intdiv($price * $rate('referral.rate.membership.l1'), 10_000);

        return [
            'site' => [Settings::string('site.name'), 'Shop name'],
            'support_email' => [Settings::string('site.support_email'), 'Support email'],
            'support_phone' => [Settings::string('site.support_phone'), 'Support phone'],
            'membership_price' => [Money::format($price), 'Membership price per month'],
            'member_discount' => [$pct($rate('pricing.member_discount_bps')), 'Member price cut on products'],
            'free_delivery_over' => [Money::format(Settings::int('shipping.member_free_over')), 'Free delivery for members from'],
            'grace_days' => [(string) Settings::int('membership.grace_days'), 'Grace days after the membership ends'],
            'early_access_hours' => [(string) Settings::int('catalog.early_access_hours'), 'Hours members can buy new products early'],
            'signals_price' => [Money::format(Settings::int('signals.guest_price')), 'Signals price per month for guests'],
            'l1_rate' => [$pct($rate('referral.rate.membership.l1')), 'Level 1 on memberships (%)'],
            'l2_rate' => [$pct($rate('referral.rate.membership.l2')), 'Level 2 on memberships (%)'],
            'l3_rate' => [$pct($rate('referral.rate.membership.l3')), 'Level 3 on memberships (%)'],
            'l1_amount' => [$share('referral.rate.membership.l1'), 'Level 1 earning from one membership'],
            'l2_amount' => [$share('referral.rate.membership.l2'), 'Level 2 earning from one membership'],
            'l3_amount' => [$share('referral.rate.membership.l3'), 'Level 3 earning from one membership'],
            'three_friends' => [Money::format(3 * $l1), 'Level 1 earning when 3 people you invited pay'],
            'shop_l1_rate' => [$pct($rate('referral.rate.products.l1')), 'Level 1 on shop orders (%)'],
            'shop_l2_rate' => [$pct($rate('referral.rate.products.l2')), 'Level 2 on shop orders (%)'],
            'shop_l3_rate' => [$pct($rate('referral.rate.products.l3')), 'Level 3 on shop orders (%)'],
            'first_payment_bonus' => [Money::format(Settings::int('bonus.first_payment')), 'First-payment bonus'],
            'loyalty_reward' => [Money::format(Settings::int('loyalty.reward')), 'Loyalty reward'],
            'loyalty_months' => [(string) Settings::int('loyalty.every_months'), 'Loyalty reward: every (months)'],
            'pending_days' => [(string) Settings::int('referral.pending_days'), 'Days earnings are on the way'],
            'link_days' => [(string) Settings::int('referral.cookie_days'), 'Days a shared link is remembered'],
            'cashout_min' => [Money::format(Settings::int('wallet.cashout_min')), 'Smallest cash-out'],
            'payout_days' => [(string) Settings::int('wallet.payout_days'), 'Days to pay a cash-out'],
            'account_hold_hours' => [(string) Settings::int('wallet.payout_account_hold_hours'), 'Wait after a new cash-out account (hours)'],
            'cashout_daily_max' => [Money::format(Settings::int('wallet.cashout_daily_max')), 'Most cash-out in 24 hours'],
            'cashout_daily_count' => [(string) Settings::int('wallet.cashout_daily_count'), 'Cash-out requests per 24 hours'],
            'unpaid_cancel_hours' => [(string) Settings::int('orders.unpaid_cancel_hours'), 'Hours before an unpaid order is cancelled'],
            'gift_card_days' => [(string) Settings::int('giftcards.valid_days'), 'Days a gift card works'],
        ];
    }

    public static function apply(string $text): string
    {
        $map = [];
        foreach (self::all() as $k => [$v]) {
            $map['{'.$k.'}'] = $v;
        }

        return strtr($text, $map);
    }
}
