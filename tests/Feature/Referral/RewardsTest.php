<?php

namespace Tests\Feature\Referral;

use App\Badges\ProfitGuard;
use App\Events\PaymentSucceeded;
use App\Fraud\FraudGuard;
use App\Ledger\Ledger;
use App\Ledger\Posting;
use App\Ledger\Wallet;
use App\Models\FraudFlag;
use App\Models\MembershipPurchase;
use App\Models\Reward;
use App\Payments\PaymentService;
use App\Rewards\Rewards;
use App\Support\Features;
use App\Support\Settings;

/** First-payment bonus and welcome-back reward: real money only, 14 days on the way, fraud holds, refunds, the profit guard. */
class RewardsTest extends ReferralTestCase
{
    public function test_first_payment_bonus_once_on_the_way_then_ready(): void
    {
        [, , $l1] = $this->chain();
        $new = $this->member($l1, 'Dawit');
        $this->payMembership($new);

        $r = Reward::query()->where('kind', 'first_payment')->firstOrFail();
        $this->assertSame($l1->id, $r->user_id);
        $this->assertSame(25_000, $r->amount);
        $this->assertSame(Reward::PENDING, $r->status);
        $this->assertSame(25_000, app(Ledger::class)->balance(Rewards::account('first_payment')));
        $this->assertSame(25_000, Wallet::for($l1)->summary()['pending'] - 50_000); // + level-1 team earnings (10%)
        $this->assertDatabaseHas('user_notifications', ['user_id' => $l1->id, 'title' => 'First-payment bonus: 250 ETB']);

        // Renewal: no second bonus.
        $this->travel(31)->days();
        $this->payMembership($new);
        $this->assertSame(1, Reward::query()->where('kind', 'first_payment')->count());

        $this->assertGreaterThan(0, app(Rewards::class)->releaseDue());
        $this->assertSame(Reward::AVAILABLE, $r->fresh()->status);
    }

    public function test_no_bonus_for_inactive_or_suspended_inviters_or_when_off(): void
    {
        $ref = $this->member(null, 'Hana');
        $ref->membership()->update(['status' => 'expired', 'current_period_end' => now()->subDays(30), 'grace_ends_at' => now()->subDays(27)]);
        $this->payMembership($this->member($ref->fresh(), 'A'));
        $this->assertSame(0, Reward::query()->count());

        $ok = $this->member(null, 'Iman');
        Features::set('referral.first_payment_bonus', false);
        $this->payMembership($this->member($ok, 'B'));
        $this->assertSame(0, Reward::query()->count());

        Features::set('referral.first_payment_bonus', true);
        Settings::set('bonus.first_payment', 0);
        $this->payMembership($this->member($ok, 'C'));
        $this->assertSame(0, Reward::query()->count());
    }

    public function test_refund_takes_it_back_and_fraud_check_holds_it(): void
    {
        [, , $l1] = $this->chain();
        $payment = $this->payMembership($this->member($l1, 'Eden'));
        app(PaymentService::class)->refund($payment, $payment->amount, 'changed mind', $this->admin->id);
        $r = Reward::query()->firstOrFail();
        $this->assertSame(Reward::REVERSED, $r->status);
        $this->assertSame(0, app(Ledger::class)->balance(Rewards::account('first_payment')));

        // Held while a fraud check is open, released when it is cleared.
        $flag = FraudFlag::query()->create(['user_id' => $l1->id, 'type' => 'self_referral_payment', 'status' => 'open', 'details' => []]);
        $this->payMembership($this->member($l1, 'Fikir'));
        $held = Reward::query()->latest('id')->firstOrFail();
        $this->assertTrue($held->on_hold);
        $this->travel(15)->days();
        app(Rewards::class)->releaseDue();
        $this->assertSame(Reward::PENDING, $held->fresh()->status);
        app(FraudGuard::class)->review($flag, 'cleared', $this->admin->id, 'ok');
        app(Rewards::class)->releaseDue();
        $this->assertSame(Reward::AVAILABLE, $held->fresh()->status);
    }

    public function test_welcome_back_reward_after_time_away(): void
    {
        Settings::set('reward.comeback', 20_000);
        $u = $this->member(null, 'Gelila');
        MembershipPurchase::query()->forceCreate(['user_id' => $u->id, 'amount' => 500_000, 'status' => 'paid',
            'period_start' => now()->subDays(80), 'period_end' => now()->subDays(50), 'paid_at' => now()->subDays(80)]);
        $u->membership()->update(['status' => 'expired', 'current_period_end' => now()->subDays(50), 'grace_ends_at' => now()->subDays(47)]);
        $this->payMembership($u->fresh());
        $r = Reward::query()->where('kind', 'comeback')->firstOrFail();
        $this->assertSame($u->id, $r->user_id);
        $this->assertSame(20_000, $r->amount);

        // Renewing on time is not a come-back.
        $this->travel(29)->days();
        $this->payMembership($u->fresh());
        $this->assertSame(1, Reward::query()->where('kind', 'comeback')->count());
    }

    public function test_profit_guard_counts_the_new_payouts_and_blocks_unsafe_numbers(): void
    {
        $w = ProfitGuard::worstCase();
        $this->assertSame(500, $w['first_payment']);   // 250 of 5,000 ETB
        $this->assertSame(0, $w['comeback']);
        $this->assertSame($w['commissions'] + $w['bonus'] + $w['loyalty'] + 500, $w['total']);
        $this->assertLessThanOrEqual(ProfitGuard::MAX_BPS, $w['total']);

        $this->actingAsAdmin();
        $this->putJson('/api/v1/admin/settings', ['settings' => ['bonus.first_payment' => 1_000_000]])
            ->assertJsonValidationErrors('profit')->assertJsonPath('errors.profit.0', fn ($m) => str_contains($m, 'first-payment bonus'));
        $this->assertSame(25_000, Settings::int('bonus.first_payment'));

        // Turning a payout switch on is checked too.
        Features::set('membership.comeback_reward', false);
        Settings::set('reward.comeback', 600_000);
        $this->putJson('/api/v1/admin/features', ['features' => ['membership.comeback_reward' => true]])->assertJsonValidationErrors('features');
        $this->assertFalse(Features::enabled('membership.comeback_reward'));
    }

    public function test_a_payment_event_seen_twice_pays_the_bonus_once(): void
    {
        $inviter = $this->member(null, 'Inviter');
        $payment = $this->payMembership($this->member($inviter, 'New'));
        event(new PaymentSucceeded($payment->fresh()));
        event(new PaymentSucceeded($payment->fresh()));
        $this->assertSame(1, Reward::query()->where('kind', 'first_payment')->where('user_id', $inviter->id)->count());
        $this->assertSame(0, (int) Posting::query()->sum('amount'));
    }
}
