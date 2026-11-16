<?php

namespace Tests\Feature\Platform;

use App\Badges\BadgeEngine;
use App\Jobs\SendCustomerMessage;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Member\MemberTables;
use App\Models\Badge;
use App\Models\Commission;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserBadge;
use App\Notify\Inbox;
use App\Notify\Message;
use App\Security\SecurityAlerts;
use App\Support\Features;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Feature\Referral\ReferralTestCase;

/** What happens behind the pages when a feature is switched off. */
class SwitchOffSideEffectsTest extends ReferralTestCase
{
    public function test_a_switched_off_feature_earns_nobody_anything_new(): void
    {
        $ref = $this->member(null, 'Almaz');
        $buyer = $this->member($ref, 'Bekele');
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 9, 'weight_grams' => 500]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'quantity' => 1])->json('token');
        $this->actingAs($buyer, 'sanctum')->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', [
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Bekele', 'phone' => '0977000111', 'city' => 'Addis Ababa', 'address' => 'CMC',
        ])->assertSuccessful();
        $this->app['auth']->forgetGuards();

        // The order was placed while the shop was on, but is paid after it was switched off.
        Features::set('shop', false);
        $this->approve(Order::query()->latest('id')->firstOrFail());
        $this->assertSame(0, Commission::query()->where('recipient_id', $ref->id)->where('type', 'products')->count());

        // Memberships still earn (their switch is on).
        $this->payMembership($buyer);
        $this->assertSame(1, Commission::query()->where('recipient_id', $ref->id)->where('type', 'membership')->count());
    }

    public function test_messages_of_a_switched_off_feature_are_never_sent_not_even_security_notices(): void
    {
        Bus::fake([SendCustomerMessage::class]);
        $u = $this->member();
        Features::set('accounts.two_factor', false);
        Features::set('wallet.cashout', false);
        app(SecurityAlerts::class)->notify($u, 'two_factor_off');
        app(SecurityAlerts::class)->notify($u, 'payout_account_changed', ['account' => '••12']);
        app(SecurityAlerts::class)->notify($u, 'password_changed');

        $sent = [];
        Bus::assertDispatched(SendCustomerMessage::class, function (SendCustomerMessage $job) use (&$sent) {
            $sent[$job->message->type] = $job->message->featureOn();

            return true;
        });
        $this->assertSame(['security_two_factor_off' => false, 'security_payout_account_changed' => false, 'security_password_changed' => true], $sent);
        $this->assertSame('accounts.two_factor', Message::featureOf('security_two_factor_on'));
        $this->assertSame('shop', Message::featureOf('order_paid'));
        $this->assertNull(Message::featureOf('security_new_sign_in'));
    }

    public function test_badges_of_a_switched_off_feature_are_hidden_and_not_given(): void
    {
        $u = $this->member();
        $team = Badge::query()->create(['key' => 'team_1', 'name' => 'Team starter', 'description' => 'Invite one active member', 'icon' => 'users',
            'color' => '#123456', 'rule' => 'active_level1', 'threshold' => 1, 'rank' => 1, 'is_active' => true]);
        $loyal = Badge::query()->create(['key' => 'loyal_1', 'name' => 'Loyal', 'description' => 'One month in a row', 'icon' => 'flame',
            'color' => '#123456', 'rule' => 'loyalty_months', 'threshold' => 0, 'rank' => 2, 'is_active' => true]);
        UserBadge::query()->create(['user_id' => $u->id, 'badge_id' => $team->id, 'unlocked_at' => now()]);

        Features::set('referral', false);
        $engine = app(BadgeEngine::class);
        $shown = collect($engine->progress($u));
        $this->assertContains($loyal->id, $shown->pluck('id'));
        $this->assertNotContains($team->id, $shown->pluck('id'));
        $this->assertSame([], $shown->whereIn('rule', ['active_level1', 'total_team', 'link_sales', 'earnings_total', 'top_referrer_month'])->all());
        $this->assertNotSame($team->id, $engine->highest($u)?->id, 'the team badge is not shown as the highest any more');
        $this->assertNotContains($team->id, array_map(fn ($b) => $b->id, $engine->evaluate($u)));

        Features::set('referral', true);
        $back = collect(app(BadgeEngine::class)->progress($u))->firstWhere('id', $team->id);
        $this->assertTrue($back['unlocked'] ?? false, 'switched back on, the badge is there again');
    }

    public function test_past_money_of_a_switched_off_feature_keeps_a_neutral_name(): void
    {
        $u = $this->member();
        app(Ledger::class)->post('commission.released', [
            [app(Ledger::class)->userAccount($u->id, Wallet::PENDING), 5_000], [app(Ledger::class)->userAccount($u->id, Wallet::AVAILABLE), -5_000],
        ], 'test:1', $u, description: 'Team earnings ready to use');

        $this->assertSame('Team earnings ready', MemberTables::moneyType('commission.released'));
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/wallet')->assertJsonPath('data.history.0.description', 'Team earnings ready to use');

        Features::set('referral', false);
        $this->assertSame('Earlier earning', MemberTables::moneyType('commission.released'));
        $this->assertSame('Earlier earning taken back (refund)', MemberTables::moneyType('commission.reversed'));
        $this->assertSame('Membership', MemberTables::earningType('membership'), 'the membership switch is still on');
        $this->getJson('/api/v1/wallet')->assertJsonPath('data.history.0.description', 'Earlier earning');
        $this->app['auth']->forgetGuards();
        $this->actingAs($u)->get('/account/wallet')->assertOk()->assertSee('Earlier earning')->assertDontSee('Team earnings');

        Features::set('shop', false);
        $this->assertSame('Earlier earning', MemberTables::earningType('products'));
        Features::set('wallet.cashout', false);
        $this->assertSame('Money sent out', MemberTables::moneyType('payout.paid'));
        $this->assertSame('Being sent', MemberTables::buckets()[Wallet::PAYOUT]);
    }

    public function test_switching_a_feature_clears_the_page_cache_at_once(): void
    {
        config(['shop.page_cache_in_tests' => true]);
        $this->get('/signals')->assertOk()->assertHeader('X-Page-Cache', 'stored');
        $this->assertFileExists(public_path('page-cache/signals.html'));

        Features::set('signals', false);
        $this->assertFileDoesNotExist(public_path('page-cache/signals.html'));
        $this->get('/signals')->assertNotFound()->assertSee("can't find that page", false);
    }

    public function test_the_404_page_and_robots_follow_the_switches(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertSee('Go to the shop')->assertSee('Track an order');
        $this->get('/robots.txt')->assertSee('Disallow: /checkout');

        Features::set('shop', false);
        $this->get('/no-such-page')->assertNotFound()->assertSee('Go to the home page')->assertDontSee('Track an order')->assertDontSee('handmade pieces');
        $this->get('/robots.txt')->assertDontSee('checkout');
    }

    public function test_bell_links_to_switched_off_pages_are_removed_in_the_app_too(): void
    {
        $u = $this->member();
        Inbox::add($u, 'cashout', 'Cash-out sent', 'We sent it.', '/account/wallet');
        Features::set('wallet', false);
        Features::set('wallet', true); // the note stays (type allowed), its link only while the page exists
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/notifications')->assertJsonPath('data.0.url', '/account/wallet');

        Features::set('shop', false);
        Inbox::add($u, 'renewal', 'Renew', 'Renew now.', '/shop');
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.url', null);
    }

    public function test_the_signed_in_shop_order_pages_are_gone_when_the_shop_is_off(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->get('/account/orders')->assertOk();
        Features::set('shop', false);
        $this->actingAs($u)->get('/account/orders')->assertNotFound();
    }
}
