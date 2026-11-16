<?php

namespace Tests\Feature\Security;

use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Payments\PaymentService;
use Tests\Feature\Referral\ReferralTestCase;

/** Refund trick: earn from a friend, spend it, then the friend asks for the money back. */
class RefundAfterSpendTest extends ReferralTestCase
{
    public function test_staff_are_warned_and_the_member_pays_it_back_from_next_earnings(): void
    {
        $abdi = $this->member(null, 'Abdi');
        $p = $this->payMembership($this->member($abdi, 'Omar'));
        $this->travel(15)->days();
        $this->artisan('referrals:release');
        $this->assertSame(75_000, Wallet::for($abdi)->available()); // 500 + 250 first payment
        $ledger = app(Ledger::class);
        $ledger->post('manual.adjustment', [[$ledger->userAccount($abdi, Wallet::AVAILABLE), 75_000], [Account::ADJUSTMENTS, -75_000]], reason: 'spent');

        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/payments/'.$p->id)->assertOk()
            ->assertJsonPath('data.refund_preview.taken_back', 75_000)->assertJsonPath('data.refund_preview.people', 1)
            ->assertJsonPath('data.refund_preview.already_used.0.amount', 75_000);

        app(PaymentService::class)->refund($p, 500_000, 'asked back', $this->admin->id);
        $this->assertSame(-75_000, Wallet::for($abdi)->available());
        $this->actingAs($abdi)->get('/account')->assertSee('Money given back: your next earnings pay this first');
        $this->actingAs($abdi, 'sanctum')->postJson('/api/v1/wallet/cash-out', ['amount' => 10_000])->assertUnprocessable();

        // His next earning first fills the hole.
        $this->payMembership($this->member($abdi, 'Ali'));
        $this->travel(15)->days();
        $this->artisan('referrals:release');
        $this->assertSame(0, Wallet::for($abdi->fresh())->available());
    }
}
