<?php

namespace Tests\Feature\Referral;

use App\Fraud\FraudGuard;
use App\Ledger\Wallet;
use App\Membership\MembershipService;
use App\Models\Commission;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Referral\CommissionEngine;
use App\Referral\TeamView;
use Illuminate\Support\Str;

/**
 * The whole referral journey the way people use it on the website:
 * open a link (or type a code) → sign up → appear in the referrer's team →
 * pay → every level up the chain earns the right amount, pending 14 days.
 */
class ReferralEndToEndTest extends ReferralTestCase
{
    private const PW = 'quiet river at dawn 7';

    /** Sign up on the website in a fresh browser; $cookie = the km_ref cookie value from opening a link. */
    private function signUp(string $first, string $phone, ?string $cookie = null, ?string $typedCode = null): User
    {
        $this->app['auth']->forgetGuards();
        $req = $cookie !== null ? $this->withCookie('km_ref', $cookie) : $this;
        $req->post('/register', array_filter([
            'first_name' => $first, 'last_name' => 'Test', 'phone' => $phone, 'password' => self::PW,
            'password_confirmation' => self::PW, 'terms' => '1', 'referral_code' => $typedCode,
        ]))->assertRedirect('/account?welcome=1');
        $this->post('/logout');
        $this->flushHeaders();

        return User::query()->where('phone', '+251'.substr($phone, 1))->firstOrFail();
    }

    /** Open a share link like a visitor and return the remembered code (cookie). */
    private function openLink(string $path): string
    {
        $r = $this->get($path);
        $cookie = $r->getCookie('km_ref');
        $this->assertNotNull($cookie, "Opening $path must remember the referral code");

        return (string) $cookie->getValue();
    }

    public function test_a_b_c_d_three_levels_through_link_ref_param_and_typed_code(): void
    {
        $a = $this->signUp('Alem', '0911300001');
        // B opens A's /r/ link, C opens a page with ?ref=, D types the code.
        $b = $this->signUp('Bini', '0911300002', $this->openLink('/r/'.$a->referral_code));
        $c = $this->signUp('Caleb', '0911300003', $this->openLink('/shop?ref='.strtolower((string) $b->fresh()->referral_code)));
        $d = $this->signUp('Dina', '0911300004', null, $c->fresh()->referral_code);

        // Saved and locked.
        $this->assertSame($a->id, $b->referrer_id);
        $this->assertSame($b->id, $c->referrer_id);
        $this->assertSame($c->id, $d->referrer_id);

        // B is in A's level 1 right away, "not active" until B pays.
        $team = app(TeamView::class)->for($a->fresh())['team'];
        $this->assertSame(1, $team['level1_count']);
        $this->assertSame(0, $team['level1_active']);
        $this->assertFalse($team['level1'][0]['active']);
        $this->assertSame(1, $team['level2_count']);
        $this->assertSame(1, $team['level3_count']);
        $this->actingAs($a->fresh(), 'web')->get('/account')->assertSee('Bini T.')->assertSee('Level 1');
        $this->app['auth']->forgetGuards();

        // Everyone becomes a paying member, top-down.
        foreach ([$a, $b, $c, $d] as $u) {
            $this->payMembership($u->fresh());
        }
        // D paid 5,000: C (L1) 10% = 500, B (L2) 5% = 250, A (L3) 2% = 100 ETB …
        // C paid: B 500, A 250. B paid: A 500.
        $this->assertSame(50_000 + 25_000 + 10_000, Wallet::for($a)->pending());
        $this->assertSame(50_000 + 25_000, Wallet::for($b)->pending());
        $this->assertSame(50_000, Wallet::for($c)->pending());
        $this->assertSame(0, Wallet::for($d)->pending());
        $this->assertSame(0, Wallet::for($a)->available(), 'pending for 14 days');
        $this->assertSame([1 => 1, 2 => 1, 3 => 1], Commission::query()->where('recipient_id', $a->id)->pluck('level')->countBy()->sortKeys()->all());

        $this->travel(15)->days();
        app(CommissionEngine::class)->releaseDue();
        $this->assertSame(85_000, Wallet::for($a)->available());
        $this->assertSame($a->id, $b->fresh()->referrer_id, 'still locked');

        // A's team now shows B as active.
        $this->assertSame(1, app(TeamView::class)->for($a->fresh())['team']['level1_active']);
        // Nobody was put on hold by these normal sign-ups.
        foreach ([$a, $b, $c] as $u) {
            $this->assertFalse(app(FraudGuard::class)->isOnHold($u));
        }
    }

    public function test_a_guest_buying_through_the_link_earns_the_referrer(): void
    {
        $a = $this->signUp('Alem', '0911300011');
        $this->payMembership($a->fresh());
        $cookie = $this->openLink('/r/'.$a->referral_code);
        $p = Product::factory()->create(['price' => 200_000, 'stock' => 5]);
        $token = $this->withCredentials()->withCookie('km_ref', $cookie)->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withCredentials()->withCookie('km_ref', $cookie)->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', [
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Guest', 'phone' => '0977111222', 'city' => 'Adama', 'address' => 'x',
        ])->assertCreated();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame($a->id, $order->referrer_user_id);
        $this->approve($order);

        $this->assertSame(12_000, Wallet::for($a)->pending()); // 6% of 2,000 ETB goods
    }

    public function test_signing_up_in_the_referrers_browser_still_links_but_holds_for_review(): void
    {
        $a = $this->member(null, 'Alem');
        // A's own browser: A used it for an order (cart token = "device").
        $p = Product::factory()->create(['stock' => 5]);
        $this->actingAs($a, 'sanctum');
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'Alem', 'phone' => '0911300021', 'city' => 'Adama', 'address' => 'x']);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/cart/items', ['product_id' => $p->id], ['X-Cart-Token' => $token]);

        // A family member signs up on the same phone with A's link.
        $id = $this->withCredentials()->withHeaders(['X-Cart-Token' => $token])->withCookie('km_ref', (string) $a->referral_code)->postJson('/api/v1/auth/register', [
            'first_name' => 'Sister', 'last_name' => 'Test', 'phone' => '0911300022', 'password' => self::PW,
        ])->assertCreated()->json('user.id');

        $this->assertSame($a->id, User::query()->findOrFail($id)->referrer_id, 'linked: the team is right');
        $this->assertTrue(app(FraudGuard::class)->isOnHold($a), 'earnings wait for review');
        $this->assertTrue(app(TeamView::class)->for($a->fresh())['under_review']);
        $this->assertDatabaseHas('activity_log', ['action' => 'referral.same_browser_linked']);
    }

    public function test_admin_tree_and_member_team_show_the_same_people(): void
    {
        $a = $this->member(null, 'Alem');
        $l1 = collect(range(1, 4))->map(fn ($i) => $this->member($a, 'Lone'.$i));
        $l2 = $l1->take(2)->map(fn ($u, $i) => $this->member($u, 'Ltwo'.$i));
        $this->member($l2->first(), 'Lthree');
        app(MembershipService::class)->adjust($l1[3], -3650, 'expired for the test', $this->admin->id);

        $member = app(TeamView::class)->for($a->fresh())['team'];
        $this->actingAs($this->admin, 'web');
        $this->admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $tree = $this->getJson("/api/v1/admin/referrals/tree/{$a->id}")->assertOk()->json('data.node');

        $this->assertSame($member['level1_count'], $this->countAt($tree, 1));
        $this->assertSame($member['level2_count'], $this->countAt($tree, 2));
        $this->assertSame($member['level3_count'], $this->countAt($tree, 3));
        $this->assertSame(4, $member['level1_count']);
        $this->assertSame(3, $member['level1_active']);
        $this->assertSame($member['level1_active'], collect($tree['children'])->where('active', true)->count());
    }

    /** People $depth levels below $node in the admin tree. */
    private function countAt(array $node, int $depth): int
    {
        $children = $node['children'] ?? [];

        return $depth === 1 ? count($children) : array_sum(array_map(fn (array $c) => $this->countAt($c, $depth - 1), $children));
    }
}
