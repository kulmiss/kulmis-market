<?php

namespace Tests\Feature\Story;

use App\Demo\Story;
use App\Ledger\LedgerCheck;
use App\Ledger\Wallet;
use App\Membership\MembershipService;
use App\Models\Commission;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use App\Models\UserBadge;
use App\Payments\PaymentService;
use App\Reports\MoneyHealth;
use App\Wallet\CashOuts;
use Database\Seeders\ShopBasicsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Referral\ReferralTestCase;

/**
 * TESTING_GUIDE.md "Must test", step by step, exactly as written there.
 * Every ETB number in the guide is checked here: if the guide and the shop
 * ever disagree, this test fails.
 */
class AbdiStoryTest extends ReferralTestCase
{
    private const PW = 'kulmis story 2026';

    private User $owner;

    private User $abdi;

    private Product $basket;

    /** Totals the guide's step 12 shows: cash in and what members got. */
    private int $cashIn = 0;

    protected function setUp(): void
    {
        parent::setUp();
        ShippingZone::query()->delete();
        $this->seed(ShopBasicsSeeder::class); // Addis Ababa: 150 ETB for up to 1 kg, as on a new shop
        $story = app(Story::class)->build();
        $this->owner = $story['owner'];
        $this->abdi = $story['abdi'];
        $this->basket = Product::query()->where('slug', 'story-basket')->firstOrFail();
        $this->cashIn = 3 * 500_000 + 2 * 500_000; // the story's history: Bekele, Chaltu, Dawit + Abdi's two payments
    }

    private function etb(int $santim): int
    {
        return intdiv($santim, 100);
    }

    /** @return array{0:int, 1:int, 2:int} [on the way, ready to use, cash-out asked] in ETB */
    private function wallet(User $u): array
    {
        $s = Wallet::for($u->fresh())->summary();

        return [$this->etb($s['pending']), $this->etb($s['available']), $this->etb($s['payout'])];
    }

    private function openLink(string $code): string
    {
        $this->app['auth']->forgetGuards();
        $cookie = $this->get('/r/'.$code)->getCookie('km_ref');
        $this->assertNotNull($cookie);

        return (string) $cookie->getValue();
    }

    private function signUp(string $key, ?string $cookie): User
    {
        $this->app['auth']->forgetGuards();
        [$first, $last, $phone] = Story::PEOPLE[$key];
        ($cookie ? $this->withCookie('km_ref', $cookie) : $this)->post('/register', [
            'first_name' => $first, 'last_name' => $last, 'phone' => '0'.substr($phone, 4), 'email' => Story::email($key),
            'password' => self::PW, 'password_confirmation' => self::PW, 'terms' => '1',
        ])->assertRedirect('/account?welcome=1');
        $this->post('/logout');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return User::query()->where('email', Story::email($key))->firstOrFail();
    }

    private function payMonth(User $u): Payment
    {
        $purchase = app(MembershipService::class)->newPurchase($u->fresh());
        $p = $this->approvedBy($purchase);
        $this->cashIn += $p->amount;

        return $p;
    }

    private function approvedBy(Model $payable): Payment
    {
        $payments = app(PaymentService::class);
        $p = $payments->submitProof($payable, Payment::TELEBIRR, UploadedFile::fake()->image('r.jpg'), 'REF', null);
        $payments->approveProof($p, $this->owner->id);

        return $p->fresh();
    }

    /** A guest buys the Story basket (2,000 ETB + 150 ETB delivery), with a link cookie and/or a typed code. */
    private function guestBuys(?string $cookie = null, ?string $typed = null, string $phone = '0911555001'): Order
    {
        $this->app['auth']->forgetGuards();
        $req = $cookie ? $this->withCredentials()->withCookie('km_ref', $cookie) : $this->withCredentials();
        $token = $req->postJson('/api/v1/cart/items', ['product_id' => $this->basket->id, 'quantity' => 1])->assertSuccessful()->json('token');
        $req->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', array_filter([
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Guest', 'phone' => $phone, 'city' => 'Addis Ababa', 'address' => 'Bole',
            'referral_code' => $typed,
        ]))->assertSuccessful();
        $this->flushHeaders();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame(215_000, $order->total, 'basket 2,000 + delivery 150');
        $this->approvedBy($order);
        $this->cashIn += $order->total;

        return $order->fresh();
    }

    /** ETB earned by $u from one payment (after money taken back), by level. @return array<int,int> */
    private function earned(User $u, ?Payment $p = null): array
    {
        return Commission::query()->where('recipient_id', $u->id)->when($p, fn ($q) => $q->where('payment_id', $p?->id))
            ->where('status', '!=', Commission::SKIPPED)->get()
            ->groupBy('level')->map(fn ($c) => $this->etb((int) $c->sum(fn ($x) => $x->amount - $x->reversed_amount)))->sortKeys()->all();
    }

    private function clock(string $by): void
    {
        $this->travel((int) $by)->days();
        foreach (['memberships:sweep', 'signals:sweep', 'referrals:release', 'badges:check'] as $job) {
            Artisan::call($job);
        }
    }

    public function test_the_abdi_story_with_every_number_of_the_guide(): void
    {
        // ── Day 0: what km:story made ─────────────────────────────────────────────
        $this->assertSame([0, 2_250, 0], $this->wallet($this->abdi), 'Abdi starts with 2,250 ETB ready: 3 × 500 team earnings + 3 × 250 first-payment bonus');
        $this->assertTrue($this->abdi->fresh()->isActiveMember());
        $this->assertSame('ABDI', $this->abdi->referral_code);
        $this->assertTrue(UserBadge::query()->where('user_id', $this->abdi->id)
            ->whereHas('badge', fn ($q) => $q->where('key', 'bronze'))->exists(), 'Bronze: 3 active people he invited');
        $this->actingAs($this->abdi)->get('/account')->assertSee('How you earn');
        $this->app['auth']->forgetGuards();

        // Step 1 (case 10): last month's badge bonus — Bronze (3 active invites) pays 200 ETB.
        Artisan::call('badges:bonus', ['--month' => now()->startOfMonth()->subMonthNoOverflow()->format('Y-m')]);
        $this->assertSame([200, 2_250, 0], $this->wallet($this->abdi), 'badge bonus 200 ETB on the way');

        // Step 2 (case 1): a guest opens Abdi's link and buys the basket → Abdi L1 6% of 2,000 = 120.
        $o1 = $this->guestBuys($this->openLink('ABDI'));
        $this->assertSame([1 => 120], $this->earned($this->abdi, $o1->payments()->first()));
        $this->assertSame([320, 2_250, 0], $this->wallet($this->abdi));

        // Step 3 (case 2): Sara signs up through Abdi's link and pays 5,000 → Abdi L1 500 + first-payment bonus 250.
        $sara = $this->signUp('sara', $this->openLink('ABDI'));
        $this->assertSame($this->abdi->id, $sara->referrer_id);
        $pSara = $this->payMonth($sara);
        $this->assertSame([1 => 500], $this->earned($this->abdi, $pSara));
        $this->assertSame([1_070, 2_250, 0], $this->wallet($this->abdi), '320 + 500 + 250');
        $this->actingAs($this->abdi)->get('/account/earnings')->assertSee('Sara')->assertSee('Membership · 5,000 ETB')
            ->assertSee('A customer who used your link')->assertSee('Shop order · 2,000 ETB');
        $this->app['auth']->forgetGuards();

        // Step 4 (case 3): Hana through Sara's link pays → Sara L1 500 + 250 bonus, Abdi L2 250.
        $hana = $this->signUp('hana', $this->openLink((string) $sara->fresh()->referral_code));
        $pHana = $this->payMonth($hana);
        $this->assertSame([1 => 500], $this->earned($sara, $pHana));
        $this->assertSame([2 => 250], $this->earned($this->abdi, $pHana));
        $this->assertSame([750, 0, 0], $this->wallet($sara));
        $this->assertSame([1_320, 2_250, 0], $this->wallet($this->abdi));
        $this->actingAs($this->abdi)->get('/account/earnings')->assertSee('Someone Sara invited')->assertDontSee('Hana');
        $this->app['auth']->forgetGuards();

        // Step 5 (case 4): Omar (Hana's link) pays → Hana L1 500 + 250, Sara L2 250, Abdi L3 100.
        $omar = $this->signUp('omar', $this->openLink((string) $hana->fresh()->referral_code));
        $pOmar = $this->payMonth($omar);
        $this->assertSame([1 => 500], $this->earned($hana, $pOmar));
        $this->assertSame([2 => 250], $this->earned($sara, $pOmar));
        $this->assertSame([3 => 100], $this->earned($this->abdi, $pOmar));
        $this->assertSame([1_420, 2_250, 0], $this->wallet($this->abdi));
        //          Ali (Omar's link) pays → Omar L1 500 + 250, Hana L2 250, Sara L3 100, Abdi NOTHING (level 4).
        $ali = $this->signUp('ali', $this->openLink((string) $omar->fresh()->referral_code));
        $pAli = $this->payMonth($ali);
        $this->assertSame([1 => 500], $this->earned($omar, $pAli));
        $this->assertSame([2 => 250], $this->earned($hana, $pAli));
        $this->assertSame([3 => 100], $this->earned($sara, $pAli));
        $this->assertSame([], $this->earned($this->abdi, $pAli), 'level 4: nobody earns for Abdi');
        $this->assertSame([1_420, 2_250, 0], $this->wallet($this->abdi));
        $this->assertSame([750, 0, 0], $this->wallet($omar));
        $this->assertSame([1_000, 0, 0], $this->wallet($hana), '500 + 250 bonus + 250 L2');
        $this->assertSame([1_100, 0, 0], $this->wallet($sara), '500 + 250 bonus + 250 + 100');

        // Step 6 (case 5): a second guest types ABDI at checkout (no link) → Abdi L1 120.
        $o2 = $this->guestBuys(null, 'abdi', '0911555002');
        $this->assertSame([1 => 120], $this->earned($this->abdi, $o2->payments()->first()));
        $this->assertSame([1_540, 2_250, 0], $this->wallet($this->abdi));

        // Step 7 (case 11): tricks. Abdi's own phone at checkout through his link → nobody earns.
        $o3 = $this->guestBuys($this->openLink('ABDI'), null, '0'.substr(Story::PEOPLE['abdi'][2], 4));
        $this->assertNull($o3->referrer_user_id);
        $this->assertSame([1_540, 2_250, 0], $this->wallet($this->abdi));
        //          … and a sign-up with Abdi's phone is refused.
        $this->app['auth']->forgetGuards();
        $this->post('/register', ['first_name' => 'Abdi', 'last_name' => 'Two', 'phone' => '0'.substr(Story::PEOPLE['abdi'][2], 4), 'password' => self::PW,
            'password_confirmation' => self::PW, 'terms' => '1'])->assertSessionHasErrors('phone');
        $this->flushSession();

        // Step 8 (case 8): Omar's membership is refunded → taken back from the whole chain above him.
        app(PaymentService::class)->refund($pOmar->fresh(), $pOmar->amount, 'Story: refund', $this->owner->id);
        $this->cashIn -= $pOmar->amount;
        $this->assertSame([], array_filter($this->earned($hana, $pOmar)), 'Hana: 500 taken back');
        $this->assertSame([], array_filter($this->earned($sara, $pOmar)), 'Sara: 250 taken back');
        $this->assertSame([], array_filter($this->earned($this->abdi, $pOmar)), 'Abdi: 100 taken back');
        $this->assertSame([1_440, 2_250, 0], $this->wallet($this->abdi), '1,540 − 100');
        $this->assertSame([250, 0, 0], $this->wallet($hana), 'only the 250 from Ali is left (her 500 + 250 bonus from Omar are taken back)');
        $this->assertSame([850, 0, 0], $this->wallet($sara), '1,100 − 250');
        $this->assertSame([750, 0, 0], $this->wallet($omar), 'Omar keeps what he earned from Ali');
        $this->assertFalse($omar->fresh()->isActiveMember());

        // Two browsers that open Abdi's link today and wait (case 6).
        $remembered29 = $this->openLink('ABDI');
        $remembered31 = $this->openLink('ABDI');

        // ── Day 15: everything from day 0 is ready (case 9) ───────────────────────
        $this->clock('15');
        $this->assertSame([0, 3_690, 0], $this->wallet($this->abdi), '2,250 + 200 + 120 + 500 + 250 + 250 + 120 = 3,690');
        $this->assertSame([0, 850, 0], $this->wallet($sara));

        // Abdi buys the basket with his wallet: member price 1,700 + delivery 150 = 1,850.
        $this->actingAs($this->abdi->fresh(), 'sanctum');
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $this->basket->id, 'quantity' => 1])->json('token');
        $res = $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', [
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Abdi', 'phone' => '0'.substr(Story::PEOPLE['abdi'][2], 4),
            'city' => 'Addis Ababa', 'address' => 'Bole', 'use_wallet' => true,
        ])->assertSuccessful();
        $this->flushHeaders();
        $this->assertSame(185_000, $res->json('data.total'));
        $this->assertSame('paid', $res->json('data.status'));
        $this->assertSame([0, 1_840, 0], $this->wallet($this->abdi), '3,690 − 1,850');

        // … and cashes out 1,000 (the smallest cash-out). More than he has is refused.
        try {
            app(CashOuts::class)->request($this->abdi->fresh(), 200_000);
            $this->fail('cash-out above the balance');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
        $req = app(CashOuts::class)->request($this->abdi->fresh(), 100_000);
        $this->assertSame([0, 840, 1_000], $this->wallet($this->abdi));
        app(CashOuts::class)->markPaid($req->fresh(), 'TB-STORY-1', $this->owner->id, true);
        $this->assertSame([0, 840, 0], $this->wallet($this->abdi));
        $this->app['auth']->forgetGuards();

        // ── Day 29 vs day 31: the 30-day link memory (case 6) ─────────────────────
        $this->clock('14');
        $o4 = $this->guestBuys($remembered29, null, '0911555004');
        $this->assertSame($this->abdi->id, $o4->referrer_user_id, 'day 29: still remembered');
        $this->assertSame([120, 840, 0], $this->wallet($this->abdi));
        $this->clock('2');
        $o5 = $this->guestBuys($remembered31, null, '0911555005');
        $this->assertNull($o5->referrer_user_id, 'day 31: forgotten, nobody earns');
        $this->assertSame([120, 840, 0], $this->wallet($this->abdi));

        // ── Day 35: Abdi's membership has ended (case 7) ─────────────────────────
        $this->clock('4');
        $this->assertFalse($this->abdi->fresh()->isActiveMember());
        $o6 = $this->guestBuys($this->openLink('ABDI'), null, '0911555006');
        $this->assertSame($this->abdi->id, $o6->referrer_user_id);
        $skipped = Commission::query()->where('recipient_id', $this->abdi->id)->where('payment_id', $o6->payments()->first()?->id)->firstOrFail();
        $this->assertSame([Commission::SKIPPED, 'not_active_member', 0], [$skipped->status, $skipped->skip_reason, $skipped->amount], 'the shop keeps the 120');
        $this->assertSame([120, 840, 0], $this->wallet($this->abdi));
        //          Abdi renews (5,000) → he earns again.
        $this->payMonth($this->abdi);
        $o7 = $this->guestBuys($this->openLink('ABDI'), null, '0911555007');
        $this->assertSame([1 => 120], $this->earned($this->abdi, $o7->payments()->first()));
        $this->assertSame([240, 840, 0], $this->wallet($this->abdi));

        // ── Step 12: the shop's money (case 12) ────────────────────────────────
        $this->assertSame(60_050 * 100, $this->cashIn, 'money paid in: 60,050 ETB');
        $this->assertTrue(LedgerCheck::balanced(), 'every entry balances');
        $h = app(MoneyHealth::class)->summary(now()->subYears(2), now()->addMinute());
        $this->assertSame(60_050 * 100, $h['money_in']);
        $this->assertSame(5_780 * 100, $h['paid_to_members'], 'Abdi 3,930 + Sara 850 + Hana 250 + Omar 750');
        $this->assertSame(['team_earnings' => 4_080 * 100, 'badge_bonus' => 200 * 100, 'rewards_and_prizes' => 1_500 * 100, 'loyalty' => 0], $h['parts'],
            'team earnings 4,080, badge bonus 200, first-payment bonuses 1,500');
        $this->assertSame(54_270 * 100, $h['kept']);
        $this->assertSame(90.4, $h['kept_percent']);
        $this->assertSame('green', $h['light']);
        // Owed now: Abdi 240 on the way + 840 ready, Sara 850, Hana 250, Omar 750 ready.
        $this->assertSame([240 * 100, (840 + 850 + 250 + 750) * 100, 0], [$h['on_the_way'], $h['ready_to_use'], $h['cash_outs_waiting']]);
    }
}
