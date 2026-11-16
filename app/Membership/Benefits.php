<?php

namespace App\Membership;

use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;

/** What members get right now (only switched-on features), in plain words. */
final class Benefits
{
    /** @return list<string> */
    public static function list(): array
    {
        return array_values(array_filter([
            Features::enabled('membership.member_discount') && Features::enabled('shop') ? 'member prices' : null,
            Features::enabled('shop.free_shipping') ? 'free delivery over '.Money::format(Settings::int('shipping.member_free_over')) : null,
            Features::enabled('shop.early_access') ? 'early access to new pieces' : null,
            Features::enabled('signals') ? 'trading signals' : null,
            Features::enabled('referral') ? 'team earnings' : null,
        ]));
    }

    /** "member prices, trading signals and team earnings" (or "benefits" when none are on). */
    public static function sentence(): string
    {
        return self::join(self::list()) ?: 'benefits';
    }

    /**
     * "a, b and c" from the parts that are not null (a part is null when its
     * feature is off). Empty string when nothing is left.
     *
     * @param  array<int, string|null>  $parts
     */
    public static function join(array $parts, string $and = 'and'): string
    {
        $all = array_values(array_filter($parts, fn ($p) => $p !== null && $p !== ''));
        $last = array_pop($all);

        return $last === null ? '' : ($all === [] ? $last : implode(', ', $all).' '.$and.' '.$last);
    }

    /** The shop's default search-engine description, from the features that are on. */
    public static function siteDescription(): string
    {
        $shop = Features::enabled('shop');
        $more = self::join([
            $shop && Features::enabled('membership') && Features::enabled('membership.member_discount') ? 'member prices' : null,
            Features::enabled('signals') ? 'trading signals' : null,
        ]);
        $start = $shop ? 'Handcrafted Ethiopian products' : Settings::string('site.name');

        return $start.($more !== '' ? ', '.$more : '').'.';
    }
}
