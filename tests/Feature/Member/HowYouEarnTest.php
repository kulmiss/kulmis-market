<?php

namespace Tests\Feature\Member;

use App\Support\Features;
use Tests\Feature\Referral\ReferralTestCase;

/** Members see clearly how they earn, and where every earning came from. */
class HowYouEarnTest extends ReferralTestCase
{
    public function test_the_dashboard_explains_earning_with_real_numbers_and_a_calculator(): void
    {
        $m = $this->member();
        $this->actingAs($m)->get('/account')->assertOk()
            ->assertSee('How you earn')
            ->assertSee('Invite 3 people who join: <b>you earn 1,500 ETB every month they stay</b> (500 ETB from each 5,000 ETB membership)', false)
            ->assertSee('250 ETB a month for each of those (level 2), and 100 ETB for each person they bring (level 3)', false)
            ->assertSee('250 ETB once, when someone you invited pays for the first time')
            ->assertSee('Bronze badge, 200 ETB extra every month')
            ->assertSee('Earnings are ready to use 14 days after each payment, and only while your own membership is paid.')
            ->assertDontSee('@if', false)->assertDontSee('@endif', false)
            ->assertSee('data-earn-calc', false)->assertSee('What you could earn');

        // The numbers follow the switches.
        Features::set('referral.level3', false);
        Features::set('badges.bonus', false);
        $this->get('/account')->assertDontSee('level 3')->assertDontSee('badge, 200 ETB');
        Features::set('referral', false);
        $this->get('/account')->assertDontSee('How you earn');
    }

    public function test_every_earning_shows_from_whom_which_payment_amount_and_when_ready(): void
    {
        [$l3, $l2, $l1] = $this->chain(); // Almaz ← Bekele ← Chaltu
        $this->payMembership($this->member($l1, 'Dawit'));

        $this->actingAs($l1)->get('/account/earnings')->assertOk()
            ->assertSee('From whom')->assertSee('Dawit')->assertSee('Membership · 5,000 ETB')->assertSee('500 ETB')->assertSee('Ready on');
        $this->actingAs($l2)->get('/account/earnings')->assertSee('Someone Chaltu invited')->assertDontSee('Dawit')->assertSee('250 ETB');
        $this->actingAs($l3)->get('/account/earnings')->assertSee('Someone in Bekele&#039;s team', false)->assertDontSee('Dawit')->assertSee('100 ETB');
        $this->actingAs($l3, 'sanctum')->getJson('/api/v1/referral/history')->assertJsonPath('data.0.from', "Someone in Bekele's team")
            ->assertJsonPath('data.0.payment', 'Membership · 5,000 ETB');
    }
}
