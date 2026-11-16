<?php

namespace Tests\Feature\Member;

use App\Member\MemberDashboard;
use App\Membership\MembershipService;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentService;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Referral\ReferralTestCase;

/** Bugs found while walking the test guide: dashboard buttons, the "we are checking" chip, staff labels and totals. */
class DashboardActionsTest extends ReferralTestCase
{
    private function sendReceipt(User $u): Payment
    {
        return app(PaymentService::class)->submitProof(app(MembershipService::class)->newPurchase($u), Payment::TELEBIRR,
            UploadedFile::fake()->image('r.jpg'), 'TX1', null);
    }

    public function test_new_customer_sees_join_and_after_sending_a_receipt_sees_we_are_checking(): void
    {
        $u = User::factory()->create(['first_name' => 'Sara', 'last_name' => 'Tesfaye', 'phone' => '+251900200002']);
        $this->actingAs($u)->get('/account')->assertOk()
            ->assertSee('Join</a>', false)->assertSee('My wallet')->assertDontSee('Renew</a>', false);

        $this->sendReceipt($u);
        $this->get('/account')->assertSee('Receipt sent · we are checking it')->assertDontSee('Join</a>', false);
    }

    public function test_member_sees_why_a_membership_receipt_was_not_accepted(): void
    {
        $u = User::factory()->create(['first_name' => 'Sara']);
        $p = $this->sendReceipt($u);
        app(PaymentService::class)->rejectProof($p, $this->admin->id, 'Name on receipt is different');
        $this->actingAs($u)->get('/account')->assertSee('We could not accept your last receipt.')->assertSee('Name on receipt is different')->assertSee('Receipt not accepted · please send a new one')
            ->assertDontSee('we are checking it')->assertSee('Join</a>', false);
    }

    public function test_member_with_money_ready_gets_cash_out_as_main_button_and_renew(): void
    {
        $m = $this->member(null, 'Abdi');
        $this->actingAs($m)->get('/account')->assertSee('Renew</a>', false)->assertSee('My wallet')->assertDontSee('Join</a>', false);
    }

    public function test_staff_see_who_a_membership_payment_is_for_and_can_search_by_name_or_phone(): void
    {
        $u = User::factory()->create(['first_name' => 'Sara', 'last_name' => 'Tesfaye', 'phone' => '+251900200002']);
        $p = $this->sendReceipt($u);
        $this->assertSame('Membership · Sara Tesfaye', $p->forLabel());

        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/payments?q=Sara')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.for', 'Membership · Sara Tesfaye');
        $this->getJson('/api/v1/admin/payments?q=0900200002')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/admin/payments?q=Nobody')->assertJsonPath('total', 0);
        $this->getJson('/api/v1/admin/payments/'.$p->id)->assertOk()->assertJsonPath('data.for', 'Membership · Sara Tesfaye');
    }

    public function test_share_kit_posts_show_the_whole_text_with_the_link(): void
    {
        $m = $this->member(null, 'Abdi');
        $this->actingAs($m)->get('/account/share')->assertOk()
            ->assertSee('rows="5"', false)->assertSee('[field-sizing:content]', false)->assertSee('/r/'.$m->referral_code);
    }

    public function test_total_earned_counts_first_payment_rewards_like_the_wallet(): void
    {
        $abdi = $this->member(null, 'Abdi');
        $this->payMembership($this->member($abdi, 'Omar'));

        $money = app(MemberDashboard::class)->money($abdi->fresh());
        $this->assertSame(75_000, $money['earned']); // 500 team + 250 first payment
        $this->assertSame(75_000, $money['this_month']);
    }
}
