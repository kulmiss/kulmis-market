<?php

namespace Tests\Feature\Money;

use App\Ledger\Ledger;
use App\Ledger\LedgerException;
use App\Ledger\Posting;
use App\Ledger\Wallet;
use App\Membership\MembershipService;
use App\Models\Commission;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Payments\ChapaGateway;
use App\Payments\PaymentService;
use App\Pricing\PricingException;
use App\Referral\CommissionEngine;
use App\Wallet\CashOuts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Feature\Referral\ReferralTestCase;

/**
 * Edge cases and abuse: wrong input, double clicks, replays, races and
 * "the browser says it costs 1 birr".
 */
class BreakItTest extends ReferralTestCase
{
    /** Every journal entry sums to zero, so the whole ledger does too. */
    private function assertLedgerBalances(): void
    {
        $this->assertSame(0, (int) Posting::query()->sum('amount'), 'Ledger does not balance.');
        $bad = DB::table('ledger_postings')->select('journal_entry_id')->groupBy('journal_entry_id')
            ->havingRaw('SUM(amount) <> 0')->pluck('journal_entry_id');
        $this->assertCount(0, $bad, 'Unbalanced entries: '.$bad->implode(','));
    }

    private function cart(Product $p, int $qty = 1): string
    {
        return (string) $this->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'quantity' => $qty])->json('token');
    }

    private function checkout(string $token, array $extra = []): TestResponse
    {
        return $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', array_merge([
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Hana', 'phone' => '0911000222',
            'city' => 'Addis Ababa', 'address' => 'Bole',
        ], $extra));
    }

    public function test_prices_and_totals_from_the_browser_are_ignored(): void
    {
        $p = Product::factory()->create(['price' => 250_000, 'stock' => 5]);
        $this->checkout($this->cart($p), [
            'total' => 100, 'price' => 100, 'merchandise_total' => 100, 'unit_price' => 100,
            'shipping' => 0, 'member_discount' => 250_000, 'is_member_order' => true, 'status' => 'paid',
        ])->assertCreated();
        $order = Order::query()->latest('id')->firstOrFail();

        $this->assertSame(250_000, $order->merchandise_total);
        $this->assertSame(270_000, $order->total);              // + 200 ETB shipping from the zone
        $this->assertSame(Order::PENDING_PAYMENT, $order->status);
        $this->assertFalse((bool) $order->is_member_order);
    }

    public function test_bad_quantities_are_refused(): void
    {
        $p = Product::factory()->create(['price' => 10_000, 'stock' => 500]);
        foreach ([0, -1, 100, 'abc', 1.5, [2]] as $qty) {
            $this->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'quantity' => $qty])->assertUnprocessable();
        }
        $this->postJson('/api/v1/cart/items', ['product_id' => 999_999])->assertUnprocessable();
    }

    public function test_the_last_coupon_use_can_only_be_taken_once(): void
    {
        Coupon::query()->create(['code' => 'ONE', 'type' => 'fixed', 'value' => 10_000, 'max_uses' => 1]);
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 10]);
        $a = $this->cart($p);
        $b = $this->cart($p);
        // Both carts accepted the coupon while it was still free…
        $this->withHeaders(['X-Cart-Token' => $a])->putJson('/api/v1/cart/coupon', ['code' => 'ONE'])->assertOk();
        $this->withHeaders(['X-Cart-Token' => $b])->putJson('/api/v1/cart/coupon', ['code' => 'ONE'])->assertOk();
        // …but only the first checkout gets it.
        $this->checkout($a)->assertCreated();
        $this->checkout($b, ['phone' => '0911000333'])->assertUnprocessable();
        $this->assertSame(1, Coupon::query()->firstOrFail()->used_count);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_webhook_replays_and_redirect_races_give_value_once(): void
    {
        config(['services.chapa.secret_key' => 'CHASECK_TEST-x', 'services.chapa.webhook_secret' => 'whsec']);
        [, , $l1] = $this->chain();
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 5]);
        $this->checkout($this->cart($p), ['referral_code' => $l1->referral_code])->assertCreated();
        $order = Order::query()->latest('id')->firstOrFail();
        Http::fake([
            'api.chapa.co/v1/transaction/initialize' => Http::response(['data' => ['checkout_url' => 'https://checkout.chapa.co/x']]),
            'api.chapa.co/v1/transaction/verify/*' => fn ($r) => Http::response(['data' => [
                'status' => 'success', 'amount' => ChapaGateway::birr($order->total), 'currency' => 'ETB',
                'tx_ref' => basename(parse_url($r->url(), PHP_URL_PATH)), 'reference' => 'AP1', 'charge' => '0']]),
        ]);
        $payment = app(PaymentService::class)->startChapa($order, 'https://x');
        $body = json_encode(['tx_ref' => $payment->tx_ref]);
        $sig = hash_hmac('sha256', $body, 'whsec');
        for ($i = 0; $i < 5; $i++) {
            $this->call('POST', '/api/v1/webhooks/chapa', [], [], [], ['HTTP_X_CHAPA_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
            $this->getJson('/api/v1/payments/chapa/callback?trx_ref='.$payment->tx_ref)->assertOk();
        }
        // A stale copy of the payment model can't pay it again either.
        $this->assertFalse(app(PaymentService::class)->markPaid($payment));

        $this->assertSame(1, DB::table('journal_entries')->where('type', 'payment.received')->count());
        $this->assertSame(3, Commission::query()->count());
        $this->assertSame(6_000, Wallet::for($l1)->pending());
        $this->assertLedgerBalances();
    }

    public function test_chapa_answer_for_a_different_transaction_is_refused(): void
    {
        config(['services.chapa.secret_key' => 'CHASECK_TEST-x']);
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 5]);
        $this->checkout($this->cart($p))->assertCreated();
        $order = Order::query()->latest('id')->firstOrFail();
        Http::fake([
            'api.chapa.co/v1/transaction/initialize' => Http::response(['data' => ['checkout_url' => 'https://checkout.chapa.co/x']]),
            'api.chapa.co/v1/transaction/verify/*' => Http::response(['data' => [
                'status' => 'success', 'amount' => '999999.00', 'currency' => 'ETB', 'tx_ref' => 'KM-SOMEONE-ELSES', 'charge' => '0']]),
        ]);
        $payment = app(PaymentService::class)->startChapa($order, 'https://x');
        app(PaymentService::class)->confirmChapa($payment->tx_ref);

        $this->assertFalse($payment->fresh()->isPaid());
        $this->assertDatabaseHas('activity_log', ['action' => 'payment.reference_mismatch']);
    }

    public function test_approving_a_proof_twice_and_wrong_refunds_are_refused(): void
    {
        $order = $this->buyProducts(100_000);
        $payment = Payment::query()->where('payable_id', $order->id)->firstOrFail();
        $this->expectExceptionCount(fn () => app(PaymentService::class)->approveProof($payment, $this->admin->id));

        $svc = app(PaymentService::class);
        foreach ([0, -100, $payment->amount + 1] as $bad) {
            $this->expectExceptionCount(fn () => $svc->refund($payment->fresh(), $bad, 'x', $this->admin->id, false));
        }
        $svc->refund($payment->fresh(), 50_000, 'part', $this->admin->id, false);
        $svc->refund($payment->fresh(), $payment->amount - 50_000, 'rest', $this->admin->id, false);
        $this->expectExceptionCount(fn () => $svc->refund($payment->fresh(), 1, 'again', $this->admin->id, false));

        $this->assertSame(1, DB::table('journal_entries')->where('type', 'payment.received')->count());
        $this->assertLedgerBalances();
    }

    public function test_many_partial_refunds_never_reverse_more_than_was_earned(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        $order = $this->buyProducts(100_000, null, $l1->referral_code);
        $payment = Payment::query()->where('payable_id', $order->id)->firstOrFail();
        $svc = app(PaymentService::class);
        // 7 odd-sized refunds that add up to the full total.
        $parts = [1_111, 33_333, 7, 50_001, 2_222, 9_999];
        $parts[] = $payment->amount - array_sum($parts);
        foreach ($parts as $i => $amount) {
            $svc->refund($payment->fresh(), $amount, "part $i", $this->admin->id, false);
        }

        foreach ([$l1, $l2, $l3] as $u) {
            $this->assertSame(0, Wallet::for($u)->pending(), 'Wallet must be back to zero, never negative.');
        }
        $this->assertSame(3, Commission::query()->where('status', Commission::REVERSED)->count());
        $this->assertTrue(Commission::query()->get()->every(fn ($c) => $c->reversed_amount === $c->amount));
        $this->assertLedgerBalances();
    }

    public function test_cash_out_double_click_cannot_take_more_than_the_balance(): void
    {
        $m = $this->member(null, 'Abdi');
        $m->forceFill(['last_name' => 'Kebede'])->save();
        app(Ledger::class)->post('manual.credit', [['equity:adjustments', 150_000], [app(Ledger::class)->userAccount($m, Wallet::AVAILABLE), -150_000]], null, null, 'test');
        $data = ['amount' => 100_000, 'method' => 'telebirr', 'account_name' => 'Abdi Kebede', 'account_number' => '0911223344'];
        app(CashOuts::class)->request($m->fresh(), $data);
        $this->expectExceptionCount(fn () => app(CashOuts::class)->request($m->fresh(), $data));

        $this->assertSame(50_000, Wallet::for($m)->available());
        $this->assertLedgerBalances();
    }

    public function test_paying_a_settled_membership_purchase_again_is_refused(): void
    {
        $buyer = User::factory()->create();
        $purchase = app(MembershipService::class)->newPurchase($buyer);
        $this->approve($purchase);
        $this->expectExceptionCount(fn () => $this->approve($purchase->fresh()));
        $this->assertSame(1, Payment::query()->where('status', Payment::PAID)->count());
    }

    public function test_a_full_day_of_mixed_activity_keeps_the_ledger_balanced(): void
    {
        [, , $l1] = $this->chain();
        $buyer = User::factory()->create(['referrer_id' => $l1->id]);
        $this->payMembership($buyer);
        $o1 = $this->buyProducts(123_457, null, $l1->referral_code);
        $o2 = $this->buyProducts(99_999, $buyer->fresh());
        $p1 = Payment::query()->where('payable_id', $o1->id)->where('payable_type', $o1->getMorphClass())->firstOrFail();
        app(PaymentService::class)->refund($p1, 12_345, 'damaged', $this->admin->id, false);
        $this->travel(15)->days();
        app(CommissionEngine::class)->releaseDue();

        $this->assertGreaterThan(0, Wallet::for($l1)->available());
        $this->assertLedgerBalances();
        $this->assertNotNull($o2->fresh()->paid_at ?? $o2->fresh()->status);
    }

    /** Runs $fn and asserts it threw (validation or domain error). */
    private function expectExceptionCount(\Closure $fn): void
    {
        try {
            $fn();
        } catch (InvalidArgumentException|ValidationException|LedgerException|PricingException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Expected the action to be refused.');
    }
}
