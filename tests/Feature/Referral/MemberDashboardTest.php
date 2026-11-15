<?php

namespace Tests\Feature\Referral;

use App\Ledger\Wallet;
use App\Membership\MembershipService;
use App\Models\Commission;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;

/** The member area: app-like home, team, earnings, wallet, orders, signals; green accent; empty states. */
class MemberDashboardTest extends ReferralTestCase
{
    public function test_a_brand_new_member_sees_friendly_empty_states(): void
    {
        $new = $this->member(null, 'Dawit');
        $this->actingAs($new, 'web');

        $this->get('/account')->assertOk()
            ->assertSee('data-empty-state', false)->assertSee('Share my link')
            ->assertSee('No earnings yet')->assertSee('No one yet')->assertSee('Nothing yet')
            ->assertSee(Money::format(0))->assertSee('Active member · renews')
            ->assertSee('My account (mobile)')->assertSee('aria-current="page"', false);
        $this->get('/account/team')->assertOk()->assertSee('No one yet');
        $this->get('/account/earnings')->assertOk()->assertSee('Nothing yet');
        $this->get('/account/wallet')->assertOk()->assertSee('No cash-outs yet');
        $this->get('/account/orders')->assertOk()->assertSee('No orders yet');
    }

    public function test_a_member_with_a_team_sees_money_levels_types_chart_and_newest(): void
    {
        [$top, $mid, $me] = $this->chain();       // $top ← $mid ← $me
        $kids = collect(range(1, 3))->map(fn ($i) => $this->member($me, "Kid$i"));
        $grand = $this->member($kids[0], 'Grandkid');
        $notPaid = User::factory()->create(['first_name' => 'Lensa', 'last_name' => 'Abdi', 'referrer_id' => $me->id, 'referred_at' => now()]);
        foreach ([...$kids, $grand] as $u) {
            $this->payMembership($u->fresh()); // each: 10% to their L1
        }
        $this->buyProducts(100_000, null, $me->referral_code);   // guest via my link: 6%

        $earned = (int) Commission::query()->where('recipient_id', $me->id)->sum('amount');
        $this->assertSame(3 * 50_000 + 25_000 + 6_000, $earned, 'L1 ×3, L2 ×1 (grandkid), products 6%');

        $this->actingAs($me, 'web');
        $page = $this->get('/account')->assertOk();
        $page->assertSee(Money::format($earned));                        // total earned
        $page->assertSee(Money::format(Wallet::for($me)->pending()));      // pending
        $page->assertSeeInOrder(['Level 1', Money::format(156_000), 'Level 2', Money::format(25_000)]); // L1 = memberships + the shop order
        $page->assertSeeInOrder(['Memberships', Money::format(175_000), 'Shop orders', Money::format(6_000)]);
        $page->assertSee('Earnings chart', false);
        $page->assertSee('Kid3 '.mb_substr($kids[2]->last_name, 0, 1).'.');
        $page->assertSee('New member');
        $page->assertSee('not active');
        $page->assertSee('3 active · 1 not active');                     // level 1: 3 paying kids; Lensa hasn't paid
        $page->assertDontSee($notPaid->phone);
        $page->assertDontSee((string) $grand->phone);
        $page->assertDontSee('Grandkid');
        $this->get('/account?chart=monthly')->assertOk()->assertSee('aria-selected="true"', false)->assertSee('Earnings chart', false);

        $team = $this->get('/account/team')->assertOk()->assertSee('Lensa A.')->assertSee('not active')->assertDontSee('Grandkid');
        $team->assertSee('Level 2');
        $this->get('/account/earnings')->assertOk()->assertSee('membership · level 1', false)->assertSee('Last 12 months');
    }

    public function test_wallet_page_cashes_out_and_signals_page_opens_telegram(): void
    {
        $me = $this->member(null, 'Abdi');
        PayoutAccount::query()->create(['user_id' => $me->id, 'method' => 'telebirr', 'account_name' => $me->name, 'account_number' => '+251911000111',
            'account_fingerprint' => hash('sha256', 'x'), 'status' => 'active', 'usable_after' => now()->subDay(), 'verified_at' => now()]);
        config(['services.telegram.bot_username' => 'KulmisSignalsBot']);
        $this->actingAs($me, 'web');

        $this->get('/account/wallet')->assertOk()->assertSee('Request cash-out')->assertSee('Cash-out account');
        $this->get('/account/signals')->assertOk()->assertSee('Open in Telegram')->assertSee('https://t.me/KulmisSignalsBot?start=', false);
        $this->get('/account/badges')->assertOk()->assertSee('My account (mobile)', false);
        $this->get('/account/security')->assertOk()->assertSee('My account (mobile)', false)->assertSee('Security');
    }

    public function test_accent_colour_is_green_by_default_and_editable_in_appearance(): void
    {
        $me = $this->member(null, 'Abdi');
        $this->actingAs($me, 'web');
        $this->get('/account')->assertSee('--m-accent: #1f8a4c', false);

        $this->admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($this->admin, 'web');
        $this->putJson('/api/v1/admin/settings', ['settings' => ['appearance.member_accent' => 'green']])->assertJsonValidationErrors('appearance.member_accent');
        $this->putJson('/api/v1/admin/settings', ['settings' => ['appearance.member_accent' => '#0E7490']])->assertOk();
        $this->assertSame('#0e7490', Settings::string('appearance.member_accent'));

        $this->actingAs($me, 'web');
        $this->get('/account')->assertSee('--m-accent: #0e7490', false);
        // The admin keeps its own look.
        $this->get('/admin')->assertDontSee('--m-accent', false);
    }

    public function test_expired_and_grace_members_see_their_status(): void
    {
        $me = $this->member(null, 'Abdi');
        $me->membership()->update(['status' => 'grace', 'current_period_end' => now()->subDay(), 'grace_ends_at' => now()->addDays(2)]);
        $this->actingAs($me->fresh(), 'web');
        $this->get('/account')->assertSee('Grace period until');

        app(MembershipService::class)->adjust($me, -3650, 'test', $this->admin->id);
        $this->get('/account')->assertSee('Membership ended')->assertSee('Become an active member to earn from your team.');
    }
}
