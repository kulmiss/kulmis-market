<?php

namespace App\Support;

/**
 * Which feature a page belongs to. A link to a page whose feature is off is
 * not shown (bell, announcements, sitemap), and the page itself answers 404.
 */
final class FeatureUrls
{
    /** Path prefix => feature(s); several = the page is there while any of them is on. Longest prefix wins. */
    public const PAGES = [
        '/signals' => 'signals', '/signals/performance' => 'signals.performance_page', '/account/signals' => 'signals',
        '/membership' => 'membership', '/membership/gift' => 'membership.gifts', '/gift' => 'membership.gifts',
        '/referral-program' => 'referral', '/r' => 'referral', '/account/team' => 'referral', '/account/earnings' => 'referral',
        '/account/share' => 'referral.share_kit', '/account/contests' => ['referral.contests', 'referral.challenges'],
        '/account/badges' => 'badges', '/account/wallet' => 'wallet', '/account/cash-out' => 'wallet.cashout',
        '/shop' => 'shop', '/c' => 'shop', '/cart' => 'shop', '/checkout' => 'shop', '/pay' => 'shop', '/account/orders' => 'shop',
        '/track' => 'shop.order_tracking', '/gift-cards' => 'shop.gift_cards', '/custom-order' => 'shop.custom_orders',
        '/downloads' => 'shop.digital', '/account/wishlist' => 'shop.wishlist', '/account/addresses' => 'shop.saved_addresses',
        '/help' => 'site.help_centre', '/account/notifications' => 'account.notifications', '/register' => 'accounts.signup',
    ];

    /** @return list<string> the feature(s) the page needs (empty = always there) */
    public static function featuresFor(string $url): array
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
        $best = null;
        foreach (array_keys(self::PAGES) as $prefix) {
            if (($path === $prefix || str_starts_with($path, $prefix.'/')) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? [] : (array) self::PAGES[$best];
    }

    /** Is the page there right now? (only our own pages are checked; other sites always count as there) */
    public static function available(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== parse_url((string) config('app.url'), PHP_URL_HOST) && $host !== request()->getHost()) {
            return true;
        }
        $features = self::featuresFor($url);

        return $features === [] || array_filter($features, fn (string $f) => Features::enabled($f)) !== [];
    }
}
