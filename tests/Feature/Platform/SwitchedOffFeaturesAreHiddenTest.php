<?php

namespace Tests\Feature\Platform;

use App\Support\Features;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Platform\Concerns\BuildsEveryFeature;
use Tests\Feature\Referral\ReferralTestCase;

/**
 * A switched-off feature leaves no trace on any page: no link, no option, no
 * checkbox, no box. With every switch on we look for each feature's markers on
 * every page (as a guest, a member with a team, a shopper); then we switch each
 * feature off on its own and check those pages again.
 *
 * Every switch in config/features.php must be listed below, either with what it
 * shows (MARKERS) or with why it shows nothing on a page (NO_SCREEN). A new
 * switch without an entry fails this test.
 */
class SwitchedOffFeaturesAreHiddenTest extends ReferralTestCase
{
    use BuildsEveryFeature;

    /** Feature => texts or HTML that only that feature shows. */
    private const MARKERS = [
        'shop' => ['~href="[^"]*/shop"~', 'Track order'],
        'shop.guest_checkout' => ['data-guest-checkout'],
        'shop.coupons' => ['data-coupon'],
        'account.welcome_tour' => ['data-tour-slide'],
        'shop.free_shipping' => ['shipping over', 'Free shipping over'],
        'shop.members_only_product' => ['Members-only product of the month'],
        'shop.early_access' => ['early access to new pieces', '-hour early access'],
        'shop.wishlist' => ['~href="[^"]*/account/wishlist"~', 'Save to wishlist'],
        'shop.stock_alerts' => ['data-stock-alert'],
        'shop.buy_again' => ['Buy again'],
        'shop.saved_addresses' => ['~href="[^"]*/account/addresses"~', 'for="saved-address"'],
        'shop.reviews' => ['Lovely basket'],
        'shop.flash_sales' => ['Flash sale'],
        'shop.bundles' => ['Coffee set'],
        'shop.upsells' => ['Frequently bought together'],
        'shop.digital' => ['Digital · download links'],
        'shop.addons' => ['>Add-on'],
        'shop.preorders' => ['Pre-order'],
        'shop.custom_orders' => ['~href="[^"]*/custom-order"~'],
        'shop.winback' => ['name="offers"'],
        'shop.gift_cards' => ['~href="[^"]*/gift-cards"~', 'data-giftcard'],
        'shop.extras.gift_wrap' => ['Gift wrapping'],
        'shop.extras.express' => ['Express: made and sent first'],
        'shop.extras.insurance' => ['Shipping insurance'],
        'shop.order_tracking' => ['Track order'],
        'payments.manual' => ['by Telebirr or bank transfer'],
        'wallet.spend' => ['name="use_wallet"'],
        'membership' => ['~href="[^"]*/membership"~'],
        'membership.member_discount' => ['Members pay'],
        'loyalty_reward' => ['loyalty reward in'],
        'membership.gifts' => ['~href="[^"]*/membership/gift"~'],
        'referral' => ['~href="[^"]*/account/team"~', 'Your referral link'],
        'referral.level1' => ['Level 1'],
        'referral.level2' => ['Level 2'],
        'referral.level3' => ['Level 3'],
        'referral.commission.membership' => ['A membership month'],
        'referral.commission.signals' => ['Signals (guest buyers)'],
        'referral.commission.products' => ['Shop products (after discounts'],
        'top_referrers' => ["This month's top referrers"],
        'referral.share_buttons' => ['data-share data-share-url', 'Telegram</a>'],
        'referral.qr_codes' => ['referral-qr.svg'],
        'referral.product_links' => ['data-product-share'],
        'referral.share_kit' => ['~href="[^"]*/account/share"~'],
        'referral.tips' => ['tips-h'],
        'referral.contests' => ['Spring race'],
        'referral.challenges' => ['Bring 3'],
        'badges' => ['~href="[^"]*/account/badges"~'],
        'badges.bonus' => ['Badge bonus'],
        'wallet' => ['~href="[^"]*/account/wallet"~'],
        'wallet.cashout' => ['Cash out'],
        'wallet.cashout_fee' => ['Cash-out fee'],
        'signals' => ['~href="[^"]*/signals"~', '~href="[^"]*/account/signals"~', 'New trading signals'],
        'signals.performance_page' => ['~href="[^"]*/signals/performance"~'],
        'accounts.signup' => ['~href="[^"]*/register"~'],
        'accounts.two_factor' => ['Two-step sign-in'],
        'account.notifications' => ['~href="[^"]*/account/notifications"~'],
        'account.goals' => ['My monthly goal'],
        'account.push' => ['data-push hidden'],
        'account.app' => ['data-install'],
        'messages.cart_reminder' => ['name="cart_reminder"'],
        'appearance.theme_switch' => ['data-theme-toggle'],
        'site.instant_loading' => ['speculationrules'],
        'site.help_centre' => ['~href="[^"]*/help"~'],
    ];

    /** Feature => why it has nothing of its own on a page (it works in the background or behind another switch's marker). */
    private const NO_SCREEN = [
        'shop.low_stock_alerts' => 'Alerts for staff only (admin).',
        'shop.coupon_floor' => 'A pricing rule; the cart only says so when a coupon is cut (PricingTest).',
        'payments.pdf_receipts' => 'Receipts in the admin only (FeatureSwitchMatrixTest).',
        'membership.comeback_reward' => 'Paid in the background; shows up as a normal earning.',
        'referral.first_payment_bonus' => 'Paid in the background; shows up as a normal earning.',
        'badges.messages' => 'Messages sent to members, not a page.',
        'telegram_bot' => 'Adds and removes people in the Telegram channel in the background.',
        'site.home_builder' => 'Picks which home page is shown; the builder page has no words of its own (HomeBuilderTest).',
        'site.offline_page' => 'No visible part: the pages only tell the browser not to use the offline copy.',
        'notifications.sms' => 'A way of sending messages, not a page.',
        'notifications.email' => 'A way of sending messages, not a page.',
        'notifications.telegram' => 'A way of sending messages, not a page.',
        'admin.setup_wizard' => 'Admin only (SetupWizardTest).',
        'site.help_feedback' => 'Only under help articles (HelpGuideTest).',
        'messages.order_paid' => 'A message sent to the customer, not a page.',
        'messages.receipt_rejected' => 'A message sent to the customer, not a page (the notice on the payment page belongs to bank / Telebirr transfers).',
        'messages.order_making' => 'A message sent to the customer, not a page.',
        'messages.order_shipped' => 'A message sent to the customer, not a page.',
        'messages.order_delivered' => 'A message sent to the customer, not a page.',
        'messages.order_cancelled' => 'A message sent to the customer, not a page.',
        'messages.back_in_stock' => 'A message sent to the customer, not a page.',
        'messages.membership_activated' => 'A message sent to the customer, not a page.',
        'messages.membership_before' => 'A message sent to the customer, not a page.',
        'messages.membership_today' => 'A message sent to the customer, not a page.',
        'messages.membership_after' => 'A message sent to the customer, not a page.',
        'messages.loyalty_reward' => 'A message sent to the customer, not a page.',
        'messages.signals_access' => 'A message sent to the customer, not a page.',
        'messages.badge_bonus' => 'A message sent to the customer, not a page.',
        'messages.gift' => 'A message sent to the customer, not a page.',
        'messages.gift_card' => 'A message sent to the customer, not a page.',
        'messages.winback' => 'A message sent to the customer, not a page.',
    ];

    /** Feature => [page => why the marker may stay there]: history, not an option. */
    private const ALLOWED = [
        'referral.level1' => ['member /account/notifications' => 'past news says which level an earning came from'],
        'referral.level2' => ['member /account/notifications' => 'past news says which level an earning came from'],
        'referral.level3' => ['member /account/notifications' => 'past news says which level an earning came from'],
    ];

    public function test_dump_pages_with_every_switch_on(): void
    {
        if (! getenv('DUMP_PAGES')) {
            $this->markTestSkipped('Only for writing the markers: DUMP_PAGES=/tmp/pages');
        }
        $this->allOn();
        $this->fixture();
        $dir = (string) getenv('DUMP_PAGES');
        @mkdir($dir, 0777, true);
        foreach ($this->render(array_keys($this->pages())) as $key => $html) {
            file_put_contents($dir.'/'.preg_replace('/[^a-z0-9]+/i', '_', $key).'.html', $html);
        }
        $this->assertTrue(true);
    }

    public function test_every_switch_is_listed_here(): void
    {
        foreach (array_keys(config('features')) as $key) {
            $this->assertTrue(isset(self::MARKERS[$key]) xor isset(self::NO_SCREEN[$key]),
                "Feature switch '$key': add what it shows to MARKERS, or why it shows nothing to NO_SCREEN.");
        }
        foreach (self::MARKERS as $key => $markers) {
            $this->assertNotSame([], $markers, "$key: give it a marker or move it to NO_SCREEN");
            $this->assertArrayHasKey($key, config('features'), "$key is not a feature switch any more");
        }
    }

    public function test_a_switched_off_feature_leaves_no_trace_on_any_page(): void
    {
        $this->allOn();
        $this->fixture();
        $all = $this->render(array_keys($this->pages()));
        $this->assertCount(count($this->pages()), $all, 'every page opens while everything is on');

        // Where does each marker show while everything is on? (It must show somewhere, or it tests nothing.)
        $where = [];
        foreach (self::MARKERS as $feature => $markers) {
            foreach ($markers as $m) {
                $found = array_keys(array_filter($all, fn ($h) => self::has($h, $m)));
                $this->assertNotSame([], $found, "Marker '$m' of $feature is on no page while everything is on: fix the marker or the test data.");
                $where[$feature] = array_merge($where[$feature] ?? [], $found);
            }
        }

        // Each switch off on its own: its markers, and those of the switches under it, are gone from those pages.
        foreach (array_keys(self::MARKERS) as $feature) {
            $family = array_values(array_filter(array_keys(self::MARKERS), fn ($k) => $this->isUnder($k, $feature)));
            $pages = array_values(array_unique(array_merge(...array_map(fn ($k) => $where[$k], $family))));
            Features::set($feature, false);
            foreach ($this->render($pages) as $key => $html) {
                foreach ($family as $k) {
                    if (isset(self::ALLOWED[$k][$key])) {
                        continue;
                    }
                    foreach (self::MARKERS[$k] as $m) {
                        $this->assertFalse(self::has($html, $m), "With '$feature' OFF, '$m' ($k) still shows on: $key");
                    }
                }
            }
            Features::set($feature, true);
        }
    }

    /** Is $key the same switch as $parent, or below it? */
    private function isUnder(string $key, string $parent): bool
    {
        for ($k = $key; $k !== null; $k = config('features')[$k][3] ?? null) {
            if ($k === $parent) {
                return true;
            }
        }

        return false;
    }

    /** A marker is plain text, or a regex between ~ ~ (for links: ~href="[^"]*\/shop"~). */
    private static function has(string $html, string $marker): bool
    {
        return str_starts_with($marker, '~') ? (bool) preg_match($marker, $html) : str_contains($html, $marker);
    }

    /** @return array<string, array{0: string, 1: string}> key => [who, path] */
    private function pages(): array
    {
        $guest = ['/', '/shop', '/shop/'.($this->slugs['plain'] ?? ''), '/shop/'.($this->slugs['sale'] ?? ''), '/shop/'.($this->slugs['bundle'] ?? ''),
            '/shop/'.($this->slugs['digital'] ?? ''), '/shop/'.($this->slugs['preorder'] ?? ''), '/shop/'.($this->slugs['addon'] ?? ''),
            '/shop/'.($this->slugs['soldout'] ?? ''), '/membership', '/membership/gift', '/signals',
            '/signals/performance', '/referral-program', '/help', '/gift-cards', '/custom-order', '/track', '/register', '/login', '/cart', '/checkout'];
        $member = ['/account', '/account/team', '/account/earnings', '/account/wallet', '/account/orders', '/account/badges', '/account/signals',
            '/account/contests', '/account/share', '/account/notifications', '/account/security', '/account/addresses', '/account/wishlist',
            '/shop/'.($this->slugs['plain'] ?? ''), '/cart', '/checkout', '/membership', '/signals'];
        $shopper = ['/account', '/shop/'.($this->slugs['plain'] ?? ''), '/membership'];
        $out = [];
        foreach (['guest' => $guest, 'member' => $member, 'shopper' => $shopper] as $who => $paths) {
            foreach ($paths as $path) {
                $out["$who $path"] = [$who, $path];
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string> key => html (only pages that answered 200)
     */
    private function render(array $keys): array
    {
        $pages = $this->pages();
        $html = [];
        foreach ($keys as $key) {
            [$who, $path] = $pages[$key];
            $this->app['auth']->forgetGuards();
            if ($who !== 'guest') {
                $this->actingAs($who === 'member' ? $this->member : $this->shopper);
            }
            $sep = str_contains($path, '?') ? '&' : '?';
            $req = $this->cart && in_array($path, ['/cart', '/checkout'], true) ? $this->withCookie('km_cart', $this->cart) : $this;
            $res = $req->get($path.$sep.'nocache='.mt_rand()); // a query string skips the page cache
            if ($res->getStatusCode() === 200) {
                $html[$key] = (string) $res->getContent();
            }
        }
        $this->app['auth']->forgetGuards();

        return $html;
    }
}
