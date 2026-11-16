<?php

namespace Tests\Feature\Platform;

use App\Models\Faq;
use App\Models\Page;
use App\Models\User;
use App\Support\Features;
use App\Support\Settings;
use Database\Seeders\PagesSeeder;
use Tests\Feature\Referral\ReferralTestCase;

/** The admin side of a switched-off feature: preview, history, and warnings about texts. */
class AdminSwitchedOffTest extends ReferralTestCase
{
    public function test_before_switching_off_the_admin_sees_who_is_affected(): void
    {
        $this->payMembership(User::factory()->create(['first_name' => 'Selam']));
        $this->payMembership(User::factory()->create(['first_name' => 'Kebede']));
        $this->actingAsAdmin();

        $lines = $this->getJson('/api/v1/admin/features/impact/membership')->assertOk()->json('data.lines');
        $this->assertStringStartsWith('2 people have membership until their month ends (the last on ', $lines[0]);
        $this->assertStringContainsString('Nobody can buy or renew', $lines[0]);
        $this->assertStringContainsString('Its admin pages and history stay', end($lines));
        $this->getJson('/api/v1/admin/features/impact/membership')->assertJsonPath('data.paid.people', 2);
        $this->getJson('/api/v1/admin/features/impact/shop.coupons')->assertOk()->assertJsonCount(1, 'data.lines');
        $this->getJson('/api/v1/admin/features/impact/nope')->assertNotFound();
    }

    public function test_admin_pages_of_a_switched_off_feature_keep_their_history(): void
    {
        $this->actingAsAdmin();
        Features::set('signals', false);
        Features::set('shop', false);
        Features::set('wallet', false);
        $this->getJson('/api/v1/admin/signals')->assertOk();
        $this->getJson('/api/v1/admin/signals/subscribers')->assertOk();
        $this->getJson('/api/v1/admin/orders')->assertOk();
        $this->getJson('/api/v1/admin/cash-outs')->assertOk();
        // …while customers get the normal 404 page.
        $this->app['auth']->forgetGuards();
        $this->get('/signals')->assertNotFound();
        $this->get('/shop')->assertNotFound();
    }

    public function test_every_staff_member_gets_the_feature_words_for_editor_warnings(): void
    {
        $this->actingAsAdmin('support');
        $res = $this->getJson('/api/v1/admin/features/state')->assertOk();
        $this->assertContains('trading', $res->json('words.signals'));
        $this->assertNotNull(collect($res->json('data'))->firstWhere('key', 'signals')['label'] ?? null);
    }

    public function test_texts_that_still_mention_a_switched_off_feature_are_listed(): void
    {
        Faq::query()->create(['question' => 'Do members get trading signals?', 'answer' => 'Yes.', 'position' => 99, 'is_published' => true]);
        Page::query()->create(['slug' => 'about', 'title' => 'About us', 'body' => "We make baskets.\n\nWe also share trading signals.", 'is_published' => true]);
        Page::query()->create(['slug' => 'signals-guide', 'title' => 'Signals guide', 'body' => 'How to read them.', 'is_published' => true]);
        Settings::set('notify.order_paid', 'Thanks {name}! {if signals}Try our signals too.{/if}');
        $this->actingAsAdmin();

        $this->assertSame([], $this->getJson('/api/v1/admin/features/texts')->assertOk()->json('data'), 'nothing to warn about while all is on');

        Features::set('signals', false);
        $rows = collect($this->getJson('/api/v1/admin/features/texts')->json('data'));
        $this->assertSame(['Trading signals'], $rows->firstWhere('title', 'Do members get trading signals?')['features']);
        $this->assertSame('Still shown: change the sentence or put it in {if …}…{/if}.', $rows->firstWhere('title', 'About us')['what_happens']);
        $this->assertSame('The whole page is hidden while it is off.', $rows->firstWhere('title', 'Signals guide')['what_happens']);
        $this->assertNull($rows->firstWhere('title', 'Message: order paid'), 'its signals part is inside {if signals}');
        $this->get('/p/signals-guide')->assertNotFound();
        $this->get('/p/about')->assertOk()->assertSee('We make baskets');
    }

    public function test_pages_drop_their_parts_about_a_switched_off_feature(): void
    {
        $this->seed(PagesSeeder::class);
        $this->get('/p/terms')->assertOk()->assertSee('Trading signals')->assertSee('It also offers a trading-signals service.');
        Features::set('signals', false);
        $this->get('/p/terms')->assertOk()->assertDontSee('signals')->assertDontSee('Trading')->assertSee('sells handcrafted products made in Ethiopia.');
        Features::set('membership', false);
        $this->get('/p/membership-terms')->assertNotFound();
        $this->get('/')->assertDontSee('Membership terms');
    }
}
