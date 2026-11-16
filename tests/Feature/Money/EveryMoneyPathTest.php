<?php

namespace Tests\Feature\Money;

use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\MoneyChecks;
use App\Ledger\Wallet;
use App\Models\Commission;
use App\Models\Coupon;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutAccount;
use App\Models\Product;
use App\Models\User;
use App\Payments\PaymentService;
use App\Shop\GiftCards;
use App\Signals\SignalAccess;
use App\Wallet\CashOuts;
use App\Wallet\PayoutAccounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Referral\ReferralTestCase;

/**
 * Every way money moves through the team program, with exact amounts. After
 * each test the "never collapses" rules are checked too (ReferralTestCase).
 * Chain: Almaz (L3) ← Bekele (L2) ← Chaltu (L1) ← the buyer.
 */
class EveryMoneyPathTest extends ReferralTestCase
{
    /** @return array<int,int> level => santim (after money taken back) for one payable */
    private function paid(Model $payable): array
    {
        return Commission::query()->where('source_type', $payable->getMorphClass())->where('source_id', $payable->getKey())
            ->where('status', '!=', Commission::SKIPPED)->get()->groupBy('level')
            ->map(fn ($c) => (int) $c->sum(fn ($x) => $x->amount - $x->reversed_amount))->sortKeys()->all();
    }

    private function cart(User $buyer, Product $p, int $qty = 1, ?string $coupon = null, ?string $giftCard = null, bool $wallet = false): Order
    {
        $this->actingAs($buyer, 'sanctum');
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'quantity' => $qty])->assertSuccessful()->json('token');
        $h = $this->withHeaders(['X-Cart-Token' => $token]);
        if ($coupon) {
            $h->putJson('/api/v1/cart/coupon', ['code' => $coupon])->assertSuccessful();
        }
        if ($giftCard) {
            $h->putJson('/api/v1/cart/gift-card', ['code' => $giftCard])->assertSuccessful();
        }
        $h->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(), 'first_name' => $buyer->first_name, 'phone' => '0977000111',
            'city' => 'Addis Ababa', 'address' => 'CMC', 'use_wallet' => $wallet])->assertSuccessful();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_memberships_on_every_level_and_level_4_gets_nothing(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        $buyer = $this->member($l1, 'Dawit');
        $four = $this->member($buyer, 'Eden'); // Eden's payment: Dawit L1, Chaltu L2, Bekele L3, Almaz nothing
        $purchase = $this->payMembership($four)->payable;
        $this->assertSame([1 => 50_000, 2 => 25_000, 3 => 10_000], $this->paid($purchase));
        $this->assertSame(0, (int) Commission::query()->where('recipient_id', $l3->id)->sum('amount'));
        $this->assertSame(10_000, Wallet::for($l2)->pending());
    }

    public function test_products_with_member_price_and_a_coupon_earn_on_what_was_really_paid(): void
    {
        [, , $l1] = $this->chain();
        $buyer = $this->member($l1, 'Dawit'); // a member: 15% member price
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 9, 'weight_grams' => 500, 'cost_materials' => 0, 'cost_labour' => 0, 'cost_packaging' => 0]);
        Coupon::query()->create(['code' => 'TEN', 'type' => 'percent', 'value' => 1_000]);
        $order = $this->cart($buyer, $p, 2, 'TEN');
        $this->approve($order);
        // 2 × 1,000 → member price 1,700 → coupon 10% → 1,530 ETB of goods; delivery is not counted.
        $this->assertSame(153_000, $order->fresh()->merchandise_total);
        $this->assertSame([1 => 9_180, 2 => 4_590, 3 => 1_530], $this->paid($order));
    }

    public function test_signals_bought_by_a_guest_earn_level_1_only(): void
    {
        [, $l2, $l1] = $this->chain();
        $guest = User::factory()->create(['first_name' => 'Hana', 'referrer_id' => $l1->id]);
        $access = app(SignalAccess::class);
        $purchase = $access->newGuestPurchase(['first_name' => 'Hana', 'phone' => '0911444555'], $guest);
        $this->approve($purchase);
        $this->assertSame([1 => 40_000], $this->paid($purchase), '10% of 4,000; signals pay level 1 only');
        $this->assertSame(0, Wallet::for($l2)->pending());
    }

    public function test_an_expired_member_earns_nothing_and_the_shop_keeps_it(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        Membership::query()->where('user_id', $l2->id)->update(['status' => 'expired', 'current_period_end' => now()->subDays(10), 'grace_ends_at' => now()->subDays(7)]);
        $purchase = $this->payMembership($this->member($l1, 'Dawit'))->payable;
        $this->assertSame([1 => 50_000, 3 => 10_000], $this->paid($purchase), 'level 2 is skipped, not passed up');
        $this->assertSame('not_active_member', Commission::query()->where('recipient_id', $l2->id)->value('skip_reason'));
        $this->assertSame(10_000, Wallet::for($l3)->pending());
    }

    public function test_full_and_partial_refunds_take_back_the_right_share_from_everyone(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        $buyer = $this->member($l1, 'Dawit');
        $p = Product::factory()->create(['price' => 200_000, 'stock' => 9, 'weight_grams' => 500, 'member_discount' => false]);
        $order = $this->cart($buyer, $p);
        $payment = $this->approve($order);
        $this->assertSame([1 => 12_000, 2 => 6_000, 3 => 2_000], $this->paid($order));
        // A quarter of the payment back → a quarter of every earning back.
        app(PaymentService::class)->refund($payment, intdiv($payment->amount, 4), 'damaged', $this->admin->id);
        $share = fn (int $x) => $x - intdiv($x * intdiv($payment->amount, 4), $payment->amount);
        $this->assertEqualsWithDelta($share(12_000), $this->paid($order)[1], 1);
        // The rest back → nothing left for anyone.
        app(PaymentService::class)->refund($payment->fresh(), $payment->fresh()->amount - $payment->fresh()->refunded_amount, 'returned', $this->admin->id);
        $this->assertSame([1 => 0, 2 => 0, 3 => 0], $this->paid($order));
        // Membership refund: team earnings and the first-payment bonus come back too.
        $m = $this->payMembership($this->member($l1, 'Eden'));
        app(PaymentService::class)->refund($m, $m->amount, 'changed mind', $this->admin->id);
        $this->assertSame([1 => 0, 2 => 0, 3 => 0], $this->paid($m->payable));
        $this->assertSame(0, (int) DB::table('rewards')->where('user_id', $l1->id)->sum(DB::raw('amount - reversed_amount')));
        $this->assertSame(0, Wallet::for($l3)->pending() + Wallet::for($l2)->pending() + Wallet::for($l1)->pending());
    }

    public function test_ready_money_spent_in_the_shop_and_cashed_out_never_more_than_there_is(): void
    {
        [, , $l1] = $this->chain();
        app(Ledger::class)->post('manual.adjustment', [[Account::ADJUSTMENTS, 300_000], [app(Ledger::class)->userAccount($l1->id, Wallet::AVAILABLE), -300_000]], reason: 'test');
        // Pay a whole order from the wallet.
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 9, 'weight_grams' => 500, 'member_discount' => false]);
        $order = $this->cart($l1, $p, 1, null, null, true);
        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame(300_000 - $order->total, Wallet::for($l1)->available());
        // Cash out more than there is: refused; the rest: fine; a "not paid" cash-out puts it back.
        $left = Wallet::for($l1)->available();
        $l1->forceFill(['created_at' => now()->subDays(60)])->save();
        app(PayoutAccounts::class)->save($l1->fresh(), ['method' => 'telebirr', 'account_name' => $l1->first_name.' '.$l1->last_name, 'account_number' => '0911223344'], 'password');
        PayoutAccount::query()->where('user_id', $l1->id)->update(['usable_after' => now()->subMinute()]);
        $this->expectCashOutRefused($l1, $left + 1);
        $req = app(CashOuts::class)->request($l1->fresh(), $left);
        $this->assertSame([0, $left], [Wallet::for($l1)->available(), Wallet::for($l1)->summary()['payout']]);
        app(CashOuts::class)->reject($req->fresh(), 'test', $this->admin->id);
        $this->assertSame($left, Wallet::for($l1)->available());
        $this->assertSame([], MoneyChecks::problems());
    }

    private function expectCashOutRefused(User $u, int $amount): void
    {
        try {
            app(CashOuts::class)->request($u->fresh(), $amount);
            $this->fail('cash-out above the balance');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_gift_card_pays_part_of_the_order_and_the_team_earns_on_the_whole_order_once(): void
    {
        [, , $l1] = $this->chain();
        $buyer = $this->member($l1, 'Dawit');
        [, $code] = app(GiftCards::class)->sellInShop(50_000, 'Aunt', null, $this->admin->id);
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 9, 'weight_grams' => 500, 'member_discount' => false]);
        $order = $this->cart($buyer, $p, 1, null, $code);
        $this->approve($order);
        $this->assertSame(50_000, $order->fresh()->gift_card_used);
        $this->assertSame([1 => 6_000, 2 => 3_000, 3 => 1_000], $this->paid($order), 'once, on the 1,000 ETB of goods');
        $this->assertSame(1, Payment::query()->where('payable_id', $order->id)->where('provider', Payment::GIFT_CARD)->count());
    }
}
