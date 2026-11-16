<?php

namespace Tests\Feature\Security;

use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Membership\MembershipService;
use App\Models\Commission;
use App\Models\MembershipPurchase;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Models\Product;
use App\Models\User;
use App\Payments\PaymentService;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Referral\ReferralTestCase;

/** The money tricks from the security check: nobody can pay less, take more, or take someone else's. */
class MoneyCheatingTest extends ReferralTestCase
{
    private function credit(User $u, int $amount): void
    {
        $l = app(Ledger::class);
        $l->post('manual.adjustment', [[$l->userAccount($u, Wallet::AVAILABLE), -$amount], [Account::ADJUSTMENTS, $amount]], reason: 'test');
    }

    private function readyAccount(User $u): void
    {
        $u->forceFill(['created_at' => now()->subDays(60)])->save();
        $this->actingAs($u, 'sanctum')->putJson('/api/v1/wallet/payout-account', ['method' => 'telebirr',
            'account_name' => $u->first_name.' '.$u->last_name, 'account_number' => '09'.random_int(10_000_000, 99_999_999), 'current_password' => 'password'])->assertOk();
        PayoutAccount::query()->where('user_id', $u->id)->update(['usable_after' => now()->subMinute(), 'verified_at' => now()]);
    }

    public function test_prices_and_totals_sent_by_the_browser_are_ignored(): void
    {
        $p = Product::factory()->create(['price' => 200_000, 'stock' => 9]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'quantity' => 1, 'price' => 100, 'unit_price' => 100])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'X', 'phone' => '0911000111', 'city' => 'Adama', 'address' => 'x',
            'total' => 100, 'merchandise_total' => 100, 'member_discount' => 199_000, 'shipping' => 0, 'wallet_used' => 200_000, 'status' => 'paid'])
            ->assertCreated()->assertJsonPath('data.status', 'pending_payment');
        $o = Order::query()->latest('id')->firstOrFail();
        $this->assertSame(200_000, $o->merchandise_total);
        $this->assertSame(0, $o->wallet_used);
        $this->assertGreaterThanOrEqual(200_000, $o->total);
    }

    public function test_a_membership_always_costs_the_set_price(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/membership/payment-proof', ['method' => 'telebirr', 'amount' => 100, 'price' => 100,
            'proof' => UploadedFile::fake()->image('r.jpg')]);
        $purchase = MembershipPurchase::query()->where('user_id', $u->id)->firstOrFail();
        $this->assertSame(Settings::int('membership.price'), $purchase->amount);
        $this->assertSame(Settings::int('membership.price'), Payment::query()->latest('id')->firstOrFail()->amount);
    }

    public function test_cash_out_never_goes_above_the_balance_even_from_two_tabs(): void
    {
        $abdi = $this->member(null, 'Abdi');
        $this->credit($abdi, 184_000);
        $this->readyAccount($abdi);
        $this->actingAs($abdi, 'sanctum');
        $this->postJson('/api/v1/wallet/cash-out', ['amount' => 200_000])->assertUnprocessable();
        $this->postJson('/api/v1/wallet/cash-out', ['amount' => -50_000])->assertUnprocessable();
        // Two tabs, two different keys: the second is refused, the wallet never goes below zero.
        $this->withHeader('Idempotency-Key', 'tab-1')->postJson('/api/v1/wallet/cash-out', ['amount' => 100_000])->assertSuccessful();
        $this->withHeader('Idempotency-Key', 'tab-2')->postJson('/api/v1/wallet/cash-out', ['amount' => 100_000])->assertUnprocessable();
        // Double click in one tab (same key): one cash-out.
        $this->withHeader('Idempotency-Key', 'tab-1')->postJson('/api/v1/wallet/cash-out', ['amount' => 100_000])->assertSuccessful();
        $this->assertSame(1, PayoutRequest::query()->where('user_id', $abdi->id)->count());
        $this->assertSame(84_000, Wallet::for($abdi)->available());
    }

    public function test_cash_out_always_goes_to_your_own_account(): void
    {
        $abdi = $this->member(null, 'Abdi');
        $eve = $this->member(null, 'Eve');
        $this->readyAccount($eve);
        $this->credit($abdi, 150_000);
        $this->readyAccount($abdi);
        $theirs = PayoutAccount::query()->where('user_id', $eve->id)->value('id');

        $this->actingAs($abdi, 'sanctum')->postJson('/api/v1/wallet/cash-out', ['amount' => 100_000, 'payout_account_id' => $theirs, 'user_id' => $eve->id])
            ->assertSuccessful();
        $r = PayoutRequest::query()->latest('id')->firstOrFail();
        $this->assertSame($abdi->id, $r->user_id);
        $this->assertNotSame($theirs, $r->payout_account_id);
        $this->assertSame(0, Wallet::for($eve)->available());
    }

    public function test_you_can_only_change_your_own_referral_code(): void
    {
        $abdi = $this->member(null, 'Abdi');
        $eve = $this->member(null, 'Eve');
        $before = $abdi->referral_code;
        $this->actingAs($eve, 'sanctum')->putJson('/api/v1/referral/code', ['code' => 'EVECODE', 'user_id' => $abdi->id])->assertOk();
        $this->actingAs($eve)->post('/account/referral-code', ['code' => 'EVEAGAIN', 'user_id' => $abdi->id]);
        $this->assertSame($before, $abdi->fresh()->referral_code);
        $this->assertSame('EVECODE', $eve->fresh()->referral_code);
    }

    public function test_refunds_never_go_above_what_was_paid_and_approving_twice_pays_once(): void
    {
        $abdi = $this->member(null, 'Abdi');
        $purchase = app(MembershipService::class)->newPurchase($this->member($abdi, 'Omar'));
        $payments = app(PaymentService::class);
        $p = $payments->submitProof($purchase, Payment::BANK, UploadedFile::fake()->image('p.jpg'), 'REF', null);
        $payments->approveProof($p, $this->admin->id);
        try {
            $payments->approveProof($p->fresh(), $this->admin->id);
        } catch (\Throwable) {
            // refused is fine
        }
        $this->assertSame(1, Commission::query()->where('recipient_id', $abdi->id)->count());

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/payments/{$p->id}/refund", ['amount' => 600_000, 'reason' => 'too much'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/payments/{$p->id}/refund", ['amount' => 500_000, 'reason' => 'asked back'])->assertSuccessful();
        $this->postJson("/api/v1/admin/payments/{$p->id}/refund", ['amount' => 500_000, 'reason' => 'twice'])->assertUnprocessable();
        $this->expectException(InvalidArgumentException::class);
        $payments->refund($p->fresh(), 1, 'once more');
    }
}
