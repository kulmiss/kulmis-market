<?php

namespace Tests\Feature\Referral;

use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\Commission;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Payments\PaymentService;
use App\Referral\CommissionEngine;
use App\Referral\ReferralCodes;
use App\Signals\SignalAccess;
use App\Support\Features;
use App\Support\Settings;

class CommissionsTest extends ReferralTestCase
{
    public function test_membership_commissions_on_three_levels(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        $buyer = User::factory()->create(['referrer_id' => $l1->id]);
        $this->payMembership($buyer);

        $this->assertSame(50_000, Wallet::for($l1)->pending());   // 10% of 5,000
        $this->assertSame(25_000, Wallet::for($l2)->pending());   // 5%
        $this->assertSame(10_000, Wallet::for($l3)->pending());   // 2%
        $this->assertSame(0, Wallet::for($l1)->available());
        $this->assertSame(3, Commission::query()->where('type', 'membership')->where('status', 'pending')->count());
    }

    public function test_product_commissions_on_paid_goods_without_shipping_after_discounts(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        // Guest buys 2 × 1,500 with L1's code: goods 3,000 (shipping 200 excluded).
        $order = $this->buyProducts(150_000, null, $l1->referral_code, [], 2);
        $this->assertSame(300_000, $order->merchandise_total);
        $this->assertSame(320_000, $order->total);

        $this->assertSame(18_000, Wallet::for($l1)->pending());   // 6% of 3,000
        $this->assertSame(9_000, Wallet::for($l2)->pending());    // 3%
        $this->assertSame(3_000, Wallet::for($l3)->pending());    // 1%
    }

    public function test_member_buyer_product_commission_uses_member_price(): void
    {
        [, , $l1] = $this->chain();
        $buyer = $this->member($l1, 'Dawit');
        $order = $this->buyProducts(200_000, $buyer);           // member price 1,700

        $this->assertSame(170_000, $order->merchandise_total);
        $this->assertSame(10_200, Wallet::for($l1)->pending());  // 6% of 1,700
    }

    public function test_coupon_discount_reduces_the_base(): void
    {
        [, , $l1] = $this->chain();
        Coupon::query()->create(['code' => 'HALF', 'type' => 'percent', 'value' => 5_000]);
        $p = Product::factory()->create(['price' => 100_000]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->putJson('/api/v1/cart/coupon', ['code' => 'HALF']);
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => 'k-'.str_repeat('a', 20),
            'first_name' => 'G', 'phone' => '0977000111', 'city' => 'Adama', 'address' => 'x', 'referral_code' => $l1->referral_code])->assertCreated();
        $this->approve(Order::query()->latest('id')->firstOrFail());

        $this->assertSame(3_000, Wallet::for($l1)->pending());   // 6% of 500
    }

    public function test_guest_signals_pay_level_one_only(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        $purchase = app(SignalAccess::class)->newGuestPurchase(['first_name' => 'Sara', 'phone' => '0966111222']);
        $purchase->subscription->update(['referrer_user_id' => $l1->id]);
        $this->approve($purchase);

        $this->assertSame(40_000, Wallet::for($l1)->pending());   // 10% of 4,000
        $this->assertSame(0, Wallet::for($l2)->pending());
        $this->assertSame(0, Wallet::for($l3)->pending());
    }

    public function test_expired_members_are_skipped_not_passed_up(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        $l2->membership->update(['status' => 'expired', 'current_period_end' => now()->subDays(10), 'grace_ends_at' => now()->subDays(7)]);
        $buyer = User::factory()->create(['referrer_id' => $l1->id]);
        $this->payMembership($buyer);

        $this->assertSame(50_000, Wallet::for($l1)->pending());
        $this->assertSame(0, Wallet::for($l2)->pending());
        $this->assertSame(10_000, Wallet::for($l3)->pending(), 'L3 still gets only its own 2%, not L2’s share');
        $skipped = Commission::query()->where('recipient_id', $l2->id)->firstOrFail();
        $this->assertSame('skipped', $skipped->status);
        $this->assertSame('not_active_member', $skipped->skip_reason);
    }

    public function test_member_in_grace_still_earns(): void
    {
        [, , $l1] = $this->chain();
        $l1->membership->update(['status' => 'grace', 'current_period_end' => now()->subDay(), 'grace_ends_at' => now()->addDays(2)]);
        $this->payMembership(User::factory()->create(['referrer_id' => $l1->id]));
        $this->assertSame(50_000, Wallet::for($l1)->pending());
    }

    public function test_guest_referrers_without_membership_earn_nothing(): void
    {
        $nonMember = User::factory()->create();
        app(ReferralCodes::class)->assign($nonMember);
        $this->buyProducts(100_000, null, $nonMember->fresh()->referral_code);
        $this->assertSame(0, Wallet::for($nonMember)->pending());
    }

    public function test_pending_becomes_available_after_14_days(): void
    {
        [, , $l1] = $this->chain();
        $this->payMembership(User::factory()->create(['referrer_id' => $l1->id]));
        $engine = app(CommissionEngine::class);

        $this->travel(13)->days();
        $this->assertSame(0, $engine->releaseDue());
        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->assertSame(3, $engine->releaseDue());
        $this->assertSame(0, $engine->releaseDue());

        $this->assertSame(0, Wallet::for($l1)->pending());
        $this->assertSame(50_000, Wallet::for($l1)->available());
    }

    public function test_on_hold_commissions_are_not_released(): void
    {
        [, , $l1] = $this->chain();
        $this->payMembership(User::factory()->create(['referrer_id' => $l1->id]));
        Commission::query()->where('recipient_id', $l1->id)->update(['on_hold' => true]);
        $this->travel(15)->days();
        app(CommissionEngine::class)->releaseDue();
        $this->assertSame(50_000, Wallet::for($l1)->pending());
    }

    public function test_full_refund_reverses_pending_commissions(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        $payment = $this->payMembership(User::factory()->create(['referrer_id' => $l1->id]));
        app(PaymentService::class)->refund($payment, 500_000, 'Refund');

        foreach ([$l1, $l2, $l3] as $u) {
            $this->assertSame(0, Wallet::for($u)->pending());
        }
        $this->assertSame(3, Commission::query()->where('status', 'reversed')->count());
        $this->assertSame(0, app(Ledger::class)->balance('expense:commissions'));
    }

    public function test_partial_refund_reverses_proportionally_and_after_release(): void
    {
        [, , $l1] = $this->chain();
        $order = $this->buyProducts(100_000, null, $l1->referral_code, [], 2);  // goods 2,000 + 200 shipping = 2,200
        $this->assertSame(12_000, Wallet::for($l1)->pending());

        $this->travel(15)->days();
        app(CommissionEngine::class)->releaseDue();
        $this->assertSame(12_000, Wallet::for($l1)->available());

        $payment = $order->payments()->firstOrFail();
        app(PaymentService::class)->refund($payment, 110_000, 'Half returned'); // half of 2,200
        $this->assertSame(6_000, Wallet::for($l1)->available());
        $this->assertSame('available', Commission::query()->where('recipient_id', $l1->id)->firstOrFail()->status);
    }

    public function test_commissions_are_never_paid_twice(): void
    {
        [, , $l1] = $this->chain();
        $payment = $this->payMembership(User::factory()->create(['referrer_id' => $l1->id]));
        app(CommissionEngine::class)->forPayment($payment);
        app(CommissionEngine::class)->forPayment($payment);
        $this->assertSame(50_000, Wallet::for($l1)->pending());
        $this->assertSame(3, Commission::query()->count());
    }

    public function test_rates_are_editable_and_levels_and_types_switchable(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        Settings::set('referral.rate.membership.l1', 1_500);
        Features::set('referral.level2', false);
        $this->payMembership(User::factory()->create(['referrer_id' => $l1->id]));

        $this->assertSame(75_000, Wallet::for($l1)->pending());
        $this->assertSame(0, Wallet::for($l2)->pending());
        $this->assertSame(10_000, Wallet::for($l3)->pending(), 'level 3 still on');

        Features::set('referral.commission.products', false);
        $this->buyProducts(100_000, null, $l1->referral_code);
        $this->assertSame(75_000, Wallet::for($l1)->pending());

        Features::set('referral', false);
        $this->payMembership(User::factory()->create(['referrer_id' => $l1->id]));
        $this->assertSame(75_000, Wallet::for($l1)->pending());
    }

    public function test_self_referral_by_phone_is_skipped(): void
    {
        [, , $l1] = $this->chain();
        // Guest order using L1's own code and L1's own phone.
        $this->buyProducts(100_000, null, $l1->referral_code, ['phone' => $l1->phone]);
        $this->assertSame(0, Wallet::for($l1)->pending());
    }
}
