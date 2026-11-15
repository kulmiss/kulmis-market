<?php

namespace Tests\Feature\Referral;

use App\Jobs\SendCustomerMessage;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Membership\Members;
use App\Models\MembershipGift;
use App\Models\Payment;
use App\Models\Reward;
use App\Models\User;
use App\Payments\PaymentService;
use App\Support\Features;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;

/** Gift membership: pay a month for someone else; they join the giver's team. */
class GiftMembershipTest extends ReferralTestCase
{
    /** @return array<string, mixed> */
    private function form(array $extra = []): array
    {
        return array_merge(['giver_name' => 'Almaz', 'giver_phone' => '0911000777', 'receiver_name' => 'Hanna', 'receiver_contact' => '0922000888',
            'message' => 'Happy Timket!', 'method' => 'telebirr', 'reference' => 'TX1', 'proof' => UploadedFile::fake()->image('r.jpg')], $extra);
    }

    private function approveGift(): MembershipGift
    {
        $gift = MembershipGift::query()->latest('id')->firstOrFail();
        app(PaymentService::class)->approveProof($gift->payments()->firstOrFail(), $this->admin->id);

        return $gift->fresh();
    }

    public function test_member_gives_a_month_receiver_joins_their_team_and_everyone_is_paid_right(): void
    {
        [, , $giver] = $this->chain(); // Chaltu, level 1 of Bekele, level 2 of Almaz
        $this->get('/membership/gift')->assertOk()->assertSee('Give a month of membership');
        $this->post('/api/v1/gifts', $this->form(['giver_phone' => $giver->phone, 'giver_name' => 'Chaltu']), ['Accept' => 'application/json'])->assertCreated();
        $gift = $this->approveGift();
        $this->assertSame('paid', $gift->status);
        $this->assertSame($giver->id, $gift->referrer_user_id);
        $this->assertSame(500_000, app(Ledger::class)->balance(MembershipGift::ACCOUNT)); // owed until used
        Bus::assertDispatched(SendCustomerMessage::class, fn ($j) => $j->message->phone === '+251922000888' && str_contains($j->message->body, '/gift/'.$gift->code));

        // Hanna opens the link, makes an account, starts the month.
        $this->get('/gift/'.$gift->code)->assertOk()->assertSee('Chaltu gave you a month')->assertSee('Happy Timket!')->assertHeader('Cache-Control', 'no-store, private');
        $hanna = User::factory()->create(['first_name' => 'Hanna', 'phone' => '+251922000888']);
        $this->actingAs($hanna)->post('/gift/'.$gift->code)->assertRedirect('/account');
        $hanna->refresh();
        $this->assertTrue(Members::isActive($hanna));
        $this->assertSame($giver->id, $hanna->referrer_id);
        $this->assertSame('redeemed', $gift->fresh()->status);
        $this->assertSame(0, app(Ledger::class)->balance(MembershipGift::ACCOUNT));
        $this->assertSame(Payment::GIFT, Payment::query()->latest('id')->value('provider'));

        // Team earnings + first-payment bonus as for any membership (cash counted once).
        $this->assertSame(50_000 + 25_000, Wallet::for($giver)->pending());
        $this->assertSame(1, Reward::query()->where('kind', 'first_payment')->where('user_id', $giver->id)->count());
        $this->assertSame(500_000, app(Ledger::class)->balance('income:memberships')); // the chain was seeded: only the gift money came in
        $this->assertSame(500_000, app(Ledger::class)->balance('asset:cash'));       // and the cash was counted once

        // Never twice.
        $this->post('/gift/'.$gift->code)->assertSessionHasErrors('gift');
    }

    public function test_guest_gift_uses_the_link_code_and_keeps_an_existing_referrer(): void
    {
        $linkOwner = $this->member(null, 'Lidya');
        $other = $this->member(null, 'Other');
        $this->withCredentials()->withCookie('km_ref', (string) $linkOwner->referral_code)
            ->post('/api/v1/gifts', $this->form(), ['Accept' => 'application/json'])->assertCreated();
        $gift = $this->approveGift();
        $this->assertSame($linkOwner->id, $gift->referrer_user_id);

        $hasRef = User::factory()->create(['referrer_id' => $other->id]);
        $this->actingAs($hasRef)->post('/gift/'.$gift->code)->assertRedirect('/account');
        $this->assertSame($other->id, $hasRef->fresh()->referrer_id); // already in a team: stays there
    }

    public function test_spam_bad_input_refunds_and_switches(): void
    {
        $this->post('/api/v1/gifts', $this->form(['website' => 'x']), ['Accept' => 'application/json'])->assertJsonValidationErrors('website');
        $this->post('/api/v1/gifts', $this->form(['receiver_contact' => 'nope']), ['Accept' => 'application/json'])->assertJsonValidationErrors('giver_phone');
        $this->get('/gift/AAAAAAAAAAAAAAAA')->assertNotFound();

        $this->post('/api/v1/gifts', $this->form(), ['Accept' => 'application/json'])->assertCreated();
        $gift = MembershipGift::query()->latest('id')->firstOrFail();
        $this->get('/gift/'.$gift->code)->assertNotFound(); // not paid yet
        $gift = $this->approveGift();

        // Refund before it is used: the link stops working.
        $pay = $gift->payments()->firstOrFail();
        app(PaymentService::class)->refund($pay, $pay->amount, 'changed mind', $this->admin->id);
        $this->assertSame('refunded', $gift->fresh()->status);
        $this->get('/gift/'.$gift->code)->assertNotFound();

        Features::set('membership.gifts', false);
        $this->get('/membership/gift')->assertNotFound();
        $this->post('/api/v1/gifts', $this->form(), ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_a_used_gift_cannot_be_refunded_from_the_gift_payment(): void
    {
        $this->post('/api/v1/gifts', $this->form(), ['Accept' => 'application/json'])->assertCreated();
        $gift = $this->approveGift();
        $this->actingAs(User::factory()->create())->post('/gift/'.$gift->code);
        $pay = $gift->payments()->firstOrFail();
        $this->expectException(\InvalidArgumentException::class);
        app(PaymentService::class)->refund($pay, $pay->amount, 'x', $this->admin->id);
    }
}
