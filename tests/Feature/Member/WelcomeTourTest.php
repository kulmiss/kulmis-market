<?php

namespace Tests\Feature\Member;

use App\Support\Features;
use App\Support\Settings;
use Tests\Feature\Referral\ReferralTestCase;

/** The 3-slide welcome tour on the member dashboard. */
class WelcomeTourTest extends ReferralTestCase
{
    public function test_shown_once_with_live_numbers_then_reopened_from_help(): void
    {
        $m = $this->member(null, 'Abdi');
        $this->actingAs($m)->get('/account')->assertOk()
            ->assertSee('data-tour', false)
            ->assertSee('Welcome, Abdi!')->assertSee('How you earn')->assertSee('Start now')
            ->assertSee('3 friends join → <b data-tour-example>1,500 ETB every month</b>', false)
            ->assertSee('on the way</b> for 14 days', false)
            ->assertSee($m->referral_code)->assertSee('/r/'.$m->referral_code)
            ->assertSee('data-help-link', false); // Help in the menu (sidebar and More)

        $this->postJson('/account/tour/seen')->assertOk();
        $this->assertNotNull($m->fresh()->welcome_tour_seen_at);
        $this->get('/account')->assertDontSee('data-tour', false);

        $this->get('/help')->assertSee('Show the welcome tour')->assertSee('/account?tour=1');
        $this->get('/account?tour=1')->assertSee('data-tour', false);
    }

    public function test_works_without_javascript(): void
    {
        $m = $this->member();
        $this->actingAs($m)->post('/account/tour/seen')->assertRedirect('/account');
        $this->assertNotNull($m->fresh()->welcome_tour_seen_at);
    }

    public function test_slides_about_switched_off_features_are_hidden(): void
    {
        $m = $this->member();
        Features::set('referral', false);
        $this->actingAs($m)->get('/account')->assertSee('data-tour', false)
            ->assertDontSee('data-tour-slide="earn"', false)->assertDontSee('Share on WhatsApp')->assertDontSee('<b>Team</b>', false)
            ->assertSee('data-tour-slide="start"', false); // still "Open Help"

        Features::set('site.help_centre', false);
        $this->get('/account')->assertDontSee('data-tour-slide="start"', false)->assertDontSee('More → Help')->assertDontSee('data-help-link', false);

        Features::set('account.welcome_tour', false);
        $this->get('/account')->assertDontSee('data-tour', false);
        $this->post('/account/tour/seen')->assertNotFound();
    }

    public function test_the_numbers_follow_the_settings(): void
    {
        Settings::set('referral.pending_days', 10);
        $m = $this->member();
        $this->actingAs($m)->get('/account')->assertSee('on the way</b> for 10 days', false);
    }
}
