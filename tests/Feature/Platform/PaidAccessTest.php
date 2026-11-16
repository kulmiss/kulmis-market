<?php

namespace Tests\Feature\Platform;

use App\Ledger\Wallet;
use App\Membership\Members;
use App\Membership\MembershipService;
use App\Membership\PaidAccess;
use App\Models\Membership;
use App\Models\SignalSubscription;
use App\Models\User;
use App\Signals\SignalAccess;
use App\Support\Features;
use App\Support\Settings;
use InvalidArgumentException;
use Tests\Feature\Referral\ReferralTestCase;

/**
 * Switching off membership or signals: people who already paid keep it until
 * their paid month ends (then they leave as normal), nobody can buy or renew,
 * and the Super Admin can end it at once with the unused days in their wallet.
 */
class PaidAccessTest extends ReferralTestCase
{
    private function paidMember(string $name = 'Selam'): User
    {
        $u = User::factory()->create(['first_name' => $name]);
        $this->payMembership($u);

        return $u->fresh();
    }

    private function signalsBuyer(string $phone = '0911223344'): SignalSubscription
    {
        $access = app(SignalAccess::class);
        $p = $access->newGuestPurchase(['first_name' => 'Hana', 'phone' => $phone], User::factory()->create(['first_name' => 'Hana']));

        return $access->activatePurchase($p)->fresh();
    }

    public function test_paid_members_keep_membership_until_their_month_ends_then_it_ends_without_grace(): void
    {
        $u = $this->paidMember();
        $sub = app(SignalAccess::class)->grantMember($u);
        Features::set('membership', false);

        $this->assertTrue(Members::isActive($u), 'paid: still a member while the switch is off');
        $this->assertTrue($sub->fresh()->isEntitled(), 'and still in the signals channel');
        $this->assertSame(0, app(MembershipService::class)->sweep()['expired']);
        $this->expectsNoRenewal($u);

        // The paid month ends: no grace days (nobody can pay), they leave the channel as normal.
        $this->travelTo(Membership::query()->where('user_id', $u->id)->value('current_period_end')->addHour());
        $this->assertFalse(Members::isActive($u->fresh()));
        $this->assertSame(1, app(MembershipService::class)->sweep()['expired']);
        $this->assertSame('expired', Membership::query()->where('user_id', $u->id)->value('status'));
        $this->assertSame('ended', $sub->fresh()->status, 'removed from the channel when the membership ended');
    }

    private function expectsNoRenewal(User $u): void
    {
        try {
            app(MembershipService::class)->newPurchase($u);
            $this->fail('membership can be bought while switched off');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->actingAs($u)->post('/account/membership/pay', ['method' => 'wallet'])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/membership/purchase')->assertNotFound();
        $this->app['auth']->forgetGuards();
    }

    public function test_signal_buyers_keep_the_channel_until_their_month_ends_and_nobody_can_buy(): void
    {
        $sub = $this->signalsBuyer();
        Features::set('signals', false);

        $this->assertTrue($sub->fresh()->isEntitled());
        $this->assertSame(0, app(SignalAccess::class)->sweep());
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->getJson('/api/v1/signals/info')->assertNotFound();
            $this->travelTo($sub->current_period_end->copy()->addHour());
            $this->assertFalse($sub->fresh()->isEntitled(), 'no grace while switched off');
            $this->assertSame(1, app(SignalAccess::class)->sweep());
            $this->assertSame('ended', $sub->fresh()->status);
        } finally {
            app(SignalAccess::class)->newGuestPurchase(['first_name' => 'Abebe', 'phone' => '0911000999']);
        }
    }

    public function test_the_admin_sees_who_keeps_it_and_the_money_for_unused_days(): void
    {
        $a = $this->paidMember('Selam');
        $this->paidMember('Kebede');
        $this->travel(15)->days();

        $sum = app(PaidAccess::class)->summary('membership');
        $this->assertSame(2, $sum['people']);
        $price = Settings::int('membership.price');
        foreach (app(PaidAccess::class)->people('membership') as $row) {
            // About half of the month is left (a month is 28–31 days).
            $this->assertEqualsWithDelta($price / 2, $row['unused'], $price * 0.06);
        }
        $this->assertTrue($sum['can_credit']);

        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/features/paid-access/membership')
            ->assertOk()->assertJsonPath('data.people', 2)->assertJsonCount(2, 'data.rows')->assertJsonPath('data.unused_total', $sum['unused_total']);
        $this->app['auth']->forgetGuards();
        $this->actingAsAdmin('manager');
        $this->getJson('/api/v1/admin/features/paid-access/membership')->assertForbidden();
        $this->assertNotNull($a);
    }

    public function test_end_access_now_only_while_off_and_adds_the_unused_days_to_the_wallet(): void
    {
        $u = $this->paidMember();
        $sub = app(SignalAccess::class)->grantMember($u);
        $this->travel(10)->days();
        $unused = app(PaidAccess::class)->people('membership')[0]['unused'];
        $this->assertGreaterThan(0, $unused);

        $admin = $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/features/paid-access/membership/end', ['credit' => true])
            ->assertStatus(422)->assertJsonValidationErrors('feature');

        Features::set('membership', false);
        $this->postJson('/api/v1/admin/features/paid-access/membership/end', ['credit' => true])
            ->assertOk()->assertJsonPath('data.ended', 1)->assertJsonPath('data.credited', 1)->assertJsonPath('data.credit_total', $unused);

        $this->assertFalse(Members::isActive($u->fresh()));
        $this->assertSame('expired', Membership::query()->where('user_id', $u->id)->value('status'));
        $this->assertSame($unused, Wallet::for($u->fresh())->available());
        $this->assertSame('ended', $sub->fresh()->status, 'removed from the signals channel too');
        $this->assertSame(0, app(PaidAccess::class)->summary('membership')['people']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($u)->get('/account/wallet')->assertOk()->assertSee('Money back for days not used');

        // Twice is harmless: nobody is left.
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'web')->postJson('/api/v1/admin/features/paid-access/membership/end', ['credit' => true])->assertJsonPath('data.ended', 0);
    }

    public function test_end_signals_now_without_money_back(): void
    {
        $sub = $this->signalsBuyer();
        Features::set('signals', false);
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/features/paid-access/signals/end', ['credit' => false])
            ->assertOk()->assertJsonPath('data.ended', 1)->assertJsonPath('data.credited', 0);
        $this->assertSame('ended', $sub->fresh()->status);
        $this->assertSame(0, Wallet::for($sub->user()->firstOrFail())->available());
        $this->getJson('/api/v1/admin/features/paid-access/wallet')->assertNotFound();
    }

    public function test_what_visitors_see_of_membership_changes_at_once(): void
    {
        config(['shop.page_cache_in_tests' => true]);
        $this->get('/membership')->assertOk()->assertSee('free delivery over')->assertHeader('X-Page-Cache', 'stored');

        Features::set('shop.free_shipping', false);
        $this->assertFileDoesNotExist(public_path('page-cache/membership.html'));
        $this->get('/membership')->assertOk()->assertDontSee('free delivery')->assertDontSee('free shipping');

        Settings::set('membership.price', 600_000);
        $this->assertFileDoesNotExist(public_path('page-cache/membership.html'));
        $this->get('/membership')->assertOk()->assertSee('6,000');
    }
}
