<?php

namespace Tests\Feature\Security;

use App\Fraud\FraudGuard;
use App\Jobs\SendCustomerMessage;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\FraudFlag;
use App\Models\Order;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Models\Product;
use App\Models\User;
use App\Payments\PaymentService;
use App\Support\Settings;
use App\Support\Totp;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Referral\ReferralTestCase;

class MoneySafetyTest extends ReferralTestCase
{
    private function rich(string $name = 'Abdi', int $amount = 2_000_000): User
    {
        $u = $this->member(null, $name);
        $u->forceFill(['last_name' => 'Kebede', 'created_at' => now()->subDays(90)])->save();
        app(Ledger::class)->post('manual.adjustment', [[app(Ledger::class)->userAccount($u, Wallet::AVAILABLE), -$amount], [Account::ADJUSTMENTS, $amount]], reason: 'test');

        return $u->fresh();
    }

    private function saveAccount(User $u, string $number = '0911223344', array $extra = []): TestResponse
    {
        return $this->actingAs($u, 'sanctum')->putJson('/api/v1/wallet/payout-account', array_merge([
            'method' => 'telebirr', 'account_name' => $u->name, 'account_number' => $number, 'current_password' => 'password'], $extra));
    }

    private function cashOut(User $u, int $amount, ?string $key = null): TestResponse
    {
        return $this->actingAs($u, 'sanctum')->withHeaders($key ? ['Idempotency-Key' => $key] : [])
            ->postJson('/api/v1/wallet/cash-out', ['amount' => $amount]);
    }

    public function test_new_payout_account_needs_password_alerts_and_waits_48_hours(): void
    {
        $u = $this->rich();
        $this->saveAccount($u, '0911223344', ['current_password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->saveAccount($u)->assertOk()->assertJsonPath('data.usable', false)->assertJsonPath('data.account', '••••••3344');
        Bus::assertDispatched(SendCustomerMessage::class, fn ($j) => $j->security && str_contains($j->message->body, 'cash-out account was changed'));

        $this->cashOut($u, 100_000)->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->travel(47)->hours();
        $this->cashOut($u, 100_000)->assertUnprocessable();
        $this->travel(2)->hours();
        $this->cashOut($u, 100_000)->assertCreated();
    }

    public function test_cash_out_without_an_account_is_refused(): void
    {
        $this->cashOut($this->rich(), 100_000)->assertUnprocessable()->assertJsonValidationErrors('account');
    }

    public function test_changing_the_account_restarts_the_wait_and_holds_open_requests(): void
    {
        $u = $this->rich();
        $this->saveAccount($u)->assertOk();
        $this->travel(49)->hours();
        $id = $this->cashOut($u, 100_000)->assertCreated()->json('data.id');

        // Same account again: nothing changes.
        $this->saveAccount($u)->assertOk()->assertJsonPath('data.usable', true);
        $this->assertSame('requested', PayoutRequest::query()->findOrFail($id)->status);

        // A different account: new wait, and the open request goes on hold.
        $this->saveAccount($u, '0922334455')->assertOk()->assertJsonPath('data.usable', false);
        $this->assertSame('on_hold', PayoutRequest::query()->findOrFail($id)->status);
        $this->cashOut($u, 100_000)->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assertSame(1, PayoutAccount::query()->where('user_id', $u->id)->where('status', 'active')->count());
    }

    public function test_two_factor_users_need_a_code_to_change_the_account(): void
    {
        $u = $this->rich();
        $u->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->saveAccount($u)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->saveAccount($u, '0911223344', ['code' => Totp::code('JBSWY3DPEHPK3PXP', intdiv(now()->getTimestamp(), 30))])->assertOk();
    }

    public function test_daily_limits_on_count_and_amount(): void
    {
        $u = $this->rich('Abdi', 10_000_000);
        $this->saveAccount($u)->assertOk();
        $this->travel(49)->hours();
        Settings::set('wallet.cashout_daily_max', 3_000_000);

        $this->cashOut($u, 2_500_000)->assertCreated();
        $this->cashOut($u, 600_000)->assertUnprocessable()->assertJsonValidationErrors('amount');   // over 30,000 ETB in 24 h
        $this->cashOut($u, 500_000)->assertCreated();                                             // exactly the limit
        $this->cashOut($u, 100_000)->assertUnprocessable();                                       // 3rd request in 24 h
        $this->travel(25)->hours();
        $this->cashOut($u, 100_000)->assertCreated();
    }

    public function test_same_idempotency_key_never_creates_a_second_cash_out(): void
    {
        $u = $this->rich();
        $this->saveAccount($u)->assertOk();
        $this->travel(49)->hours();
        $key = (string) Str::uuid();
        $a = $this->cashOut($u, 100_000, $key)->assertCreated()->json('data.id');
        $b = $this->cashOut($u, 100_000, $key)->assertCreated()->json('data.id');

        $this->assertSame($a, $b);
        $this->assertSame(1, PayoutRequest::query()->count());
        $this->assertSame(100_000, Wallet::for($u)->summary()['payout']);

        // Website form: a double click posts the same hidden request_id twice.
        $this->actingAs($u, 'web');
        $form = ['amount_birr' => '1000', 'request_id' => Str::random(32)];
        $this->post('/account/cash-out', $form)->assertRedirect();
        $this->post('/account/cash-out', $form)->assertRedirect();
        $this->assertSame(2, PayoutRequest::query()->count());
    }

    public function test_first_cash_out_of_a_new_account_is_held_for_review(): void
    {
        $u = $this->rich();
        $u->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->saveAccount($u->fresh())->assertOk();
        $this->travel(49)->hours();
        $id = $this->cashOut($u, 100_000)->assertCreated()->json('data.id');
        $r = PayoutRequest::query()->findOrFail($id);
        $this->assertSame('on_hold', $r->status);
        $this->assertStringContainsString('new account', (string) $r->hold_reason);
    }

    public function test_money_only_goes_to_a_verified_account(): void
    {
        $u = $this->rich();
        $this->saveAccount($u)->assertOk();
        $this->travel(49)->hours();
        $first = $this->cashOut($u, 100_000)->json('data.id');
        $this->travel(25)->hours();
        $second = $this->cashOut($u, 100_000)->json('data.id');
        $this->app['auth']->forgetGuards();
        $admin = $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/cash-outs/$first")->assertJsonPath('data.account_verified', false);
        $this->postJson("/api/v1/admin/cash-outs/$first/pay", ['reference' => 'TB1'])->assertUnprocessable()->assertJsonValidationErrors('payout');
        $this->postJson("/api/v1/admin/cash-outs/$first/pay", ['reference' => 'TB1', 'account_checked' => true])->assertOk();
        $this->assertSame($admin->id, PayoutAccount::query()->firstOrFail()->verified_by);
        // Once verified, later payouts to the same account don't ask again.
        $this->postJson("/api/v1/admin/cash-outs/$second/pay", ['reference' => 'TB2'])->assertOk();
    }

    public function test_pay_with_chapa_twice_reuses_the_open_checkout(): void
    {
        config(['services.chapa.secret_key' => 'CHASECK_TEST-x']);
        Http::fake(['api.chapa.co/v1/transaction/initialize' => Http::response(['data' => ['checkout_url' => 'https://checkout.chapa.co/one']])]);
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 3]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'H', 'phone' => '0911000999', 'city' => 'Adama', 'address' => 'x'])->assertCreated();
        $order = Order::query()->firstOrFail();

        $a = $this->postJson("/api/v1/orders/{$order->number}/pay", ['method' => 'chapa', 'phone' => '0911000999'])->assertCreated()->json('data.tx_ref');
        $b = $this->postJson("/api/v1/orders/{$order->number}/pay", ['method' => 'chapa', 'phone' => '0911000999'])->assertCreated()->json('data.tx_ref');
        $this->assertSame($a, $b);
        Http::assertSentCount(1);

        $this->travel(31)->minutes(); // an old abandoned checkout is not reused
        $c = app(PaymentService::class)->startChapa($order->fresh(), 'https://x')->tx_ref;
        $this->assertNotSame($a, $c);
    }

    public function test_signups_from_one_browser_or_network_flag_the_referrer(): void
    {
        $ref = $this->member(null, 'Ref');
        $device = fn () => $this->postJson('/api/v1/cart/items', ['product_id' => Product::factory()->create(['stock' => 5])->id])->json('token');
        $cart = $device();
        foreach (['0911000001', '0911000002'] as $i => $phone) {
            $this->withHeaders(['X-Cart-Token' => $cart])->postJson('/api/v1/auth/register', ['first_name' => 'Fake', 'last_name' => (string) $i,
                'phone' => $phone, 'password' => 'quiet river at dawn 7', 'referral_code' => $ref->referral_code])->assertCreated();
            $this->app['auth']->forgetGuards();
        }
        $this->assertTrue(FraudFlag::query()->where('user_id', $ref->id)->where('type', 'shared_device_signups')->exists());

        $this->flushHeaders(); // new people, new browsers
        $ref2 = $this->member(null, 'Other');
        foreach (array_map(fn ($n) => '09110000'.(11 + $n), range(0, 9)) as $i => $phone) {
            $this->withServerVariables(['REMOTE_ADDR' => '196.188.10.'.(20 + $i)])->postJson('/api/v1/auth/register', ['first_name' => 'Net', 'last_name' => (string) $i,
                'phone' => $phone, 'password' => 'quiet river at dawn 7', 'referral_code' => $ref2->referral_code])->assertCreated();
            $this->app['auth']->forgetGuards();
        }
        $flag = FraudFlag::query()->where('user_id', $ref2->id)->where('type', 'same_network_signups')->firstOrFail();
        $this->assertSame(10, $flag->details['count_24h']);
        $this->assertSame(10, $ref2->referrals()->count(), 'still linked — they may be real people');
        // Review only: many Ethiopian mobile users share one carrier IP, so this never holds money.
        $this->assertFalse(app(FraudGuard::class)->isOnHold($ref2));
        $this->assertDatabaseMissing('users', ['signup_device_hash' => '196.188.10.20']); // no IP stored anywhere
    }

    public function test_sign_ups_are_rate_limited_per_network(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/register', ['first_name' => 'R', 'last_name' => (string) $i, 'phone' => '091100010'.$i,
                'password' => 'quiet river at dawn 7'])->assertCreated();
            $this->app['auth']->forgetGuards();
        }
        $this->postJson('/api/v1/auth/register', ['first_name' => 'R', 'last_name' => '6', 'phone' => '0911000106',
            'password' => 'quiet river at dawn 7'])->assertStatus(429);
    }
}
