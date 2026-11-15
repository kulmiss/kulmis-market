<?php

namespace Tests\Feature\Referral;

use App\Fraud\FraudGuard;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\Commission;
use App\Models\FraudFlag;
use App\Models\Order;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Models\Product;
use App\Models\User;
use App\Payments\PaymentService;
use App\Referral\CommissionEngine;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class WalletCashOutTest extends ReferralTestCase
{
    private function credit(User $u, int $amount): void
    {
        app(Ledger::class)->post('manual.adjustment', [[app(Ledger::class)->userAccount($u, Wallet::AVAILABLE), -$amount], [Account::ADJUSTMENTS, $amount]], reason: 'test');
    }

    /** Saves a cash-out account and skips the 48-hour wait (tested in MoneySafetyTest). */
    private function account(User $u, string $number = '0911223344', ?string $name = null): TestResponse
    {
        $u->forceFill(['created_at' => now()->subDays(60)])->save();
        $r = $this->actingAs($u, 'sanctum')->putJson('/api/v1/wallet/payout-account', ['method' => 'telebirr',
            'account_name' => $name ?? $u->first_name.' '.$u->last_name, 'account_number' => $number, 'current_password' => 'password']);
        PayoutAccount::query()->where('user_id', $u->id)->update(['usable_after' => now()->subMinute()]);

        return $r;
    }

    private function cashOut(User $u, array $data): TestResponse
    {
        if (! PayoutAccount::query()->where('user_id', $u->id)->exists() && Features::enabled('wallet.cashout')) {
            $this->account($u)->assertOk();
        }

        return $this->actingAs($u, 'sanctum')->postJson('/api/v1/wallet/cash-out', array_merge(['amount' => 150_000], $data));
    }

    public function test_pay_order_fully_from_wallet_creates_commissions(): void
    {
        [, , $l1] = $this->chain();
        $buyer = $this->member($l1, 'Dawit');
        $this->credit($buyer, 500_000);
        $p = Product::factory()->create(['price' => 200_000]);
        $this->actingAs($buyer, 'sanctum');
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');

        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'Dawit', 'phone' => '0977000222', 'city' => 'Adama', 'address' => 'x', 'use_wallet' => true])
            ->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.amount_due', 0);

        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame(190_000, $order->wallet_used);      // 1,700 + 200 shipping
        $this->assertSame(310_000, Wallet::for($buyer)->available());
        $this->assertSame(10_200, Wallet::for($l1)->pending()); // 6% of 1,700 goods
        $this->assertSame(190_000, app(Ledger::class)->balance('income:sales'));
    }

    public function test_partial_wallet_then_chapa_for_the_rest(): void
    {
        $buyer = $this->member(null, 'Dawit');
        $this->credit($buyer, 50_000);
        $p = Product::factory()->create(['price' => 200_000]);
        $this->actingAs($buyer, 'sanctum');
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'Dawit', 'phone' => '0977000222', 'city' => 'Adama', 'address' => 'x', 'use_wallet' => true])
            ->assertCreated()->assertJsonPath('data.amount_due', 140_000)->assertJsonPath('data.status', 'pending_payment');

        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame(0, Wallet::for($buyer)->available());
        $this->approve($order); // the remaining 1,400
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_cancelled_unpaid_order_gives_wallet_money_back(): void
    {
        $buyer = $this->member(null, 'Dawit');
        $this->credit($buyer, 50_000);
        $p = Product::factory()->create(['price' => 200_000]);
        $this->actingAs($buyer, 'sanctum');
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'Dawit', 'phone' => '0977000222', 'city' => 'Adama', 'address' => 'x', 'use_wallet' => true]);

        $this->travel(49)->hours();
        $this->artisan('orders:cancel-unpaid');
        $this->assertSame(50_000, Wallet::for($buyer)->available());
    }

    public function test_membership_renewal_from_wallet(): void
    {
        $buyer = $this->member(null, 'Dawit');
        $this->credit($buyer, 600_000);
        $before = $buyer->membership->current_period_end;
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/membership/pay', ['method' => 'wallet'])
            ->assertCreated()->assertJsonPath('data.paid', true);
        $this->assertSame(100_000, Wallet::for($buyer)->available());
        $this->assertTrue($buyer->fresh()->membership->current_period_end->gt($before));

        $this->postJson('/api/v1/membership/pay', ['method' => 'wallet'])->assertUnprocessable(); // only 1,000 left
    }

    public function test_wallet_spend_switch(): void
    {
        Features::set('wallet.spend', false);
        $buyer = $this->member(null, 'Dawit');
        $this->credit($buyer, 600_000);
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/membership/pay', ['method' => 'wallet'])->assertUnprocessable();
        $this->assertSame(600_000, Wallet::for($buyer)->available());
    }

    public function test_cash_out_minimum_balance_and_name_match(): void
    {
        $u = $this->member(null, 'Abdi');
        $u->forceFill(['last_name' => 'Mohammed'])->save();
        $this->credit($u, 120_000);

        $this->account($u, '0911223344', 'Someone Else')->assertUnprocessable()->assertJsonValidationErrors('account_name');
        $this->account($u, '0911223344', 'MOHAMMED Abdi')->assertOk();
        $this->cashOut($u, ['amount' => 99_999])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->cashOut($u, ['amount' => 150_000])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->cashOut($u, ['amount' => 100_000])->assertCreated();
        $this->assertSame(['pending' => 0, 'available' => 20_000, 'payout' => 100_000], Wallet::for($u)->summary());
        $this->assertTrue(PayoutRequest::query()->firstOrFail()->due_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
    }

    public function test_admin_pays_or_rejects_cash_out(): void
    {
        $u = $this->member(null, 'Abdi');
        $this->credit($u, 300_000);
        $id1 = $this->cashOut($u, ['amount' => 100_000])->json('data.id');
        $id2 = $this->cashOut($u, ['amount' => 100_000])->json('data.id');
        $this->app['auth']->forgetGuards();

        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/cash-outs?status=requested')->assertJsonPath('total', 2)->assertJsonPath('data.0.account', '••••••3344');
        $this->getJson("/api/v1/admin/cash-outs/$id1")->assertJsonPath('data.account_number', '0911223344');
        // First payout to an account: Super Admin must confirm the name first.
        $this->postJson("/api/v1/admin/cash-outs/$id1/pay", ['reference' => 'TB-PAY-1'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/cash-outs/$id1/pay", ['reference' => 'TB-PAY-1', 'account_checked' => true])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/v1/admin/cash-outs/$id1/pay", ['reference' => 'again', 'account_checked' => true])->assertUnprocessable();
        $this->postJson("/api/v1/admin/cash-outs/$id2/reject", ['reason' => 'Wrong number'])->assertOk();

        $this->assertSame(['pending' => 0, 'available' => 200_000, 'payout' => 0], Wallet::for($u)->summary());
        $this->assertSame(-100_000, app(Ledger::class)->balance(Account::CASH));
    }

    public function test_finance_role_can_approve_but_support_cannot(): void
    {
        $this->actingAsAdmin('support');
        $this->getJson('/api/v1/admin/cash-outs')->assertForbidden();
        $this->actingAsAdmin('finance');
        $this->getJson('/api/v1/admin/cash-outs')->assertOk();
    }

    public function test_shared_payout_account_flags_both_and_holds(): void
    {
        $a = $this->member(null, 'Abdi');
        $b = $this->member(null, 'Bontu');
        $this->credit($a, 200_000);
        $this->credit($b, 200_000);
        $this->cashOut($a, ['amount' => 100_000])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->account($b, '0911223344', 'Bontu '.$b->last_name)->assertOk();   // the same Telebirr number as Abdi
        $this->cashOut($b, ['amount' => 100_000])->assertCreated();

        $this->assertTrue(app(FraudGuard::class)->isOnHold($a));
        $this->assertTrue(app(FraudGuard::class)->isOnHold($b));
        $held = PayoutRequest::query()->where('user_id', $b->id)->firstOrFail();
        $this->assertSame('on_hold', $held->status);

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/cash-outs/{$held->id}/pay", ['reference' => 'x', 'account_checked' => true])->assertUnprocessable();
        FraudFlag::query()->where('user_id', $b->id)->each(fn ($f) => $this->postJson("/api/v1/admin/fraud/{$f->id}/review", ['decision' => 'cleared'])->assertOk());
        $this->assertSame('requested', $held->fresh()->status);
    }

    public function test_flagged_members_earnings_stay_pending(): void
    {
        [, , $l1] = $this->chain();
        app(FraudGuard::class)->flag($l1, 'manual');
        $this->payMembership(User::factory()->create(['referrer_id' => $l1->id]));
        $this->travel(15)->days();
        app(CommissionEngine::class)->releaseDue();
        $this->assertSame(50_000, Wallet::for($l1)->pending());
        $this->assertTrue(Commission::query()->where('recipient_id', $l1->id)->firstOrFail()->on_hold);
    }

    public function test_paying_with_own_payout_account_is_self_referral(): void
    {
        [, , $l1] = $this->chain();
        $order = $this->buyProducts(100_000, null, $l1->referral_code, ['phone' => '0955000111']);
        $this->assertSame(6_000, Wallet::for($l1)->pending());

        // Second order paid with L1's own phone as payer account → skipped + flagged.
        $p = Product::factory()->create(['price' => 100_000]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'G', 'phone' => '0955000222', 'city' => 'Adama', 'address' => 'x', 'referral_code' => $l1->referral_code]);
        $o2 = Order::query()->latest('id')->firstOrFail();
        $pay = app(PaymentService::class)->submitProof($o2, 'telebirr', UploadedFile::fake()->image('a.jpg'), 'r', $l1->phone);
        app(PaymentService::class)->approveProof($pay, $this->admin->id);

        $this->assertSame(6_000, Wallet::for($l1)->pending());
        $this->assertTrue(FraudFlag::query()->where('user_id', $l1->id)->where('type', 'self_referral_payment')->exists());
    }

    public function test_same_device_signup_is_linked_but_held_for_review(): void
    {
        $ref = $this->member(null, 'Abdi');
        // Referrer used this device (cart) for an order.
        $p = Product::factory()->create();
        $this->actingAs($ref, 'sanctum');
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'Abdi', 'phone' => '0911555666', 'city' => 'Adama', 'address' => 'x']);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/cart/items', ['product_id' => $p->id], ['X-Cart-Token' => $token]);

        $res = $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/auth/register', [
            'first_name' => 'Fake', 'last_name' => 'Friend', 'phone' => '0911777888', 'password' => 'abc-12345-xyz-go', 'referral_code' => $ref->referral_code,
        ])->assertCreated();
        // Linked (could be a shared family phone)…
        $this->assertSame($ref->id, User::query()->findOrFail($res->json('user.id'))->referrer_id);
        // …but the referrer's earnings wait for review.
        $this->assertTrue(FraudFlag::query()->where('user_id', $ref->id)->where('type', 'same_browser_signup')->exists());
        $this->assertTrue(app(FraudGuard::class)->isOnHold($ref));
    }

    public function test_daily_signup_limit_flags_for_review(): void
    {
        Settings::set('referral.daily_signup_limit', 2);
        $ref = $this->member(null, 'Abdi');
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/register', ['first_name' => "N$i", 'last_name' => 'X', 'phone' => '091100000'.$i,
                'password' => 'abc-12345-xyz-go', 'referral_code' => $ref->referral_code])->assertCreated();
        }
        $this->assertTrue(FraudFlag::query()->where('user_id', $ref->id)->where('type', 'daily_limit')->exists());
        $this->assertSame(3, $ref->referrals()->count(), 'still linked, but held for review');
    }

    public function test_cashout_switch_off(): void
    {
        $u = $this->member(null, 'Abdi');
        Features::set('wallet.cashout', false);
        $this->cashOut($u, [])->assertNotFound();
    }

    public function test_wallet_endpoint_shows_balances_history_and_masked_cashouts(): void
    {
        $u = $this->member(null, 'Abdi');
        $this->credit($u, 150_000);
        $this->cashOut($u, ['amount' => 100_000])->assertCreated();
        $json = $this->getJson('/api/v1/wallet')->assertOk()->json('data');
        $this->assertSame(50_000, $json['balances']['available']);
        $this->assertSame('••••••3344', $json['cashouts'][0]['account']);
        $this->assertNotEmpty($json['history']);
    }

    public function test_admin_payout_reminder(): void
    {
        config(['services.telegram.bot_token' => 'B', 'services.telegram.admin_chat_id' => '42']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $u = $this->member(null, 'Abdi');
        $this->credit($u, 150_000);
        $this->cashOut($u, ['amount' => 100_000]);
        $this->travel(60)->hours();
        $this->artisan('payouts:remind')->expectsOutputToContain('Reminded about: 1')->assertSuccessful();
        Http::assertSent(fn ($r) => $r['chat_id'] === '42' && str_contains($r['text'], 'Cash-outs to pay'));
    }
}
