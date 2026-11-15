<?php

namespace Tests\Feature\Referral;

use App\Badges\BadgeEngine;
use App\Jobs\SendCustomerMessage;
use App\Membership\MembershipService;
use App\Models\Badge;
use App\Models\User;
use App\Models\UserBadge;
use App\Support\Features;
use App\Support\Totp;
use Illuminate\Support\Facades\Bus;

class BadgesTest extends ReferralTestCase
{
    private function engine(): BadgeEngine
    {
        return app(BadgeEngine::class);
    }

    /** @return list<string> */
    private function keys(User $u): array
    {
        return UserBadge::query()->where('user_id', $u->id)->with('badge')->get()->pluck('badge.key')->sort()->values()->all();
    }

    private function row(User $u, string $key): array
    {
        return collect($this->engine()->progress($u))->firstWhere('key', $key);
    }

    private function superAdmin(): User
    {
        $this->admin->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($this->admin, 'web');

        return $this->admin;
    }

    public function test_default_badges_and_thresholds(): void
    {
        $t = Badge::query()->pluck('threshold', 'key');
        $this->assertSame([3, 10, 25, 50, 100], [$t['bronze'], $t['silver'], $t['gold'], $t['platinum'], $t['diamond']]);
        $this->assertSame([3, 6, 12], [$t['loyal_3'], $t['loyal_6'], $t['loyal_12']]);
        $this->assertSame([1_000_000, 5_000_000, 10_000_000, 50_000_000], [$t['earned_10k'], $t['earned_50k'], $t['earned_100k'], $t['earned_500k']]);
        $this->assertSame([1, 5, 10], [$t['first_order'], $t['orders_5'], $t['orders_10']]);
        $this->assertSame(100, $t['early_supporter']);
        $this->assertSame(1, $t['first_sale']);
        $this->assertSame(1, $t['top_referrer']);
    }

    public function test_team_size_progress_reads_3_of_10_to_silver_and_unlocks_bronze(): void
    {
        $me = $this->member(null, 'Abdi');
        foreach (range(1, 3) as $i) {
            $this->member($me, "F$i");
        }
        User::factory()->create(['referrer_id' => $me->id]); // not a member: doesn't count

        $this->assertSame('3/10 active members to Silver', $this->row($me, 'silver')['label']);
        $this->assertSame(30, $this->row($me, 'silver')['percent']);
        $this->assertContains('bronze', array_map(fn ($b) => $b->key, $this->engine()->evaluate($me)));
        $this->assertTrue($this->row($me, 'bronze')['unlocked']);
        $this->assertSame('Silver', $this->engine()->summary($me)['next']['name']);

        // Kept when a team member's membership ends.
        app(MembershipService::class)->adjust(User::query()->where('first_name', 'F1')->firstOrFail(), -3650, 'test', $this->admin->id);
        $this->engine()->evaluate($me);
        $this->assertContains('bronze', $this->keys($me));
    }

    public function test_total_team_counts_all_three_levels(): void
    {
        Badge::query()->where('key', 'team_25')->update(['threshold' => 3]);
        $a = $this->member(null, 'A');
        $b = $this->member($a, 'B');
        $c = $this->member($b, 'C');
        $this->member($c, 'D');
        $this->member($this->member($c, 'D2'), 'E'); // level 4 from A: not counted

        $this->assertSame(4, $this->engine()->metric($a, 'total_team'));
        $this->engine()->evaluate($a);
        $this->assertContains('team_25', $this->keys($a));
    }

    public function test_loyalty_streak_unlocks_by_the_daily_check(): void
    {
        $me = $this->member(null, 'Abdi');
        $me->membership()->update(['continuous_since' => now(), 'current_period_end' => now()->addMonths(7), 'grace_ends_at' => now()->addMonths(7)->addDays(3)]);
        $this->travel(3)->months();
        $this->travel(1)->days();
        $this->artisan('badges:check')->assertSuccessful();
        $this->assertContains('loyal_3', $this->keys($me));
        $this->assertNotContains('loyal_6', $this->keys($me));
        $this->assertSame('3/6 months in a row to Loyal 6 months', $this->row($me, 'loyal_6')['label']);
    }

    public function test_first_sale_through_my_link_and_earnings_and_shopper_unlock_after_payment(): void
    {
        $this->superAdmin();
        $this->putJson('/api/v1/admin/badges/'.Badge::query()->where('key', 'earned_10k')->value('id'), ['threshold' => 10_000])->assertOk();
        $this->app['auth']->forgetGuards();

        $me = $this->member(null, 'Abdi');
        $this->buyProducts(200_000, null, $me->referral_code); // guest buys with my code → 6% = 120 ETB

        $this->assertContains('first_sale', $this->keys($me));
        $this->assertContains('earned_10k', $this->keys($me));

        $shopper = $this->member(null, 'Selam');
        $this->buyProducts(100_000, $shopper);
        $this->assertContains('first_order', $this->keys($shopper));
        $this->assertSame('1/5 orders to Regular', $this->row($shopper, 'orders_5')['label']);
    }

    public function test_top_referrer_of_the_month_and_early_supporter(): void
    {
        Badge::query()->where('key', 'early_supporter')->update(['threshold' => 2]);
        $a = $this->member(null, 'Abdi');
        $b = $this->member(null, 'Bontu');
        $this->member($a, 'A1');
        $this->member($a, 'A2');
        $this->member($b, 'B1');

        $this->assertSame(1, $this->engine()->awardTopReferrers(now()));
        $this->assertContains('top_referrer', $this->keys($a));
        $this->assertNotContains('top_referrer', $this->keys($b));

        foreach ([$a, $b, User::query()->where('first_name', 'A1')->firstOrFail()] as $u) {
            $this->engine()->evaluate($u);
        }
        $this->assertContains('early_supporter', $this->keys($a));
        $this->assertContains('early_supporter', $this->keys($b));
        $this->assertNotContains('early_supporter', $this->keys(User::query()->where('first_name', 'A1')->firstOrFail()));
    }

    public function test_unlock_shows_a_celebration_and_an_in_app_notification(): void
    {
        $me = $this->member(null, 'Abdi');
        foreach (range(1, 3) as $i) {
            $this->member($me, "F$i");
        }
        $this->engine()->evaluate($me);

        $this->assertDatabaseHas('user_notifications', ['user_id' => $me->id, 'type' => 'badge_unlocked', 'title' => 'New badge: Bronze']);
        $this->assertDatabaseHas('activity_log', ['action' => 'badge.unlocked']);
        Bus::assertNotDispatched(SendCustomerMessage::class); // messages are off by default

        $this->actingAs($me, 'web');
        $this->get('/account')->assertOk()->assertSee('data-celebrate', false)->assertSee('2 new badges unlocked!')->assertSee('Bronze');
        $this->post('/account/badges/seen')->assertRedirect();
        $this->get('/account')->assertDontSee('data-celebrate', false)->assertSee('Next badge');
        $this->get('/account/badges')->assertOk()->assertSee('My badges')->assertSee('3/10 active members to Silver')
            ->assertSee('role="progressbar"', false)->assertSee('Shopping');

        $this->actingAs($me, 'sanctum');
        $this->getJson('/api/v1/badges')->assertOk()->assertJsonPath('summary.highest.key', 'bronze')
            ->assertJsonPath('summary.unseen', []);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 2)->assertJsonPath('data.0.url', '/account/badges');
        $this->postJson('/api/v1/notifications/read')->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread', 0);
    }

    public function test_badge_messages_go_out_when_switched_on(): void
    {
        Features::set('badges.messages', true);
        $me = $this->member(null, 'Abdi');
        $this->engine()->evaluate($me);
        Bus::assertDispatched(SendCustomerMessage::class, fn ($job) => str_contains($job->message->body, 'You unlocked the Early supporter badge'));
    }

    public function test_super_admin_edits_adds_switches_off_and_deletes_badges(): void
    {
        $this->superAdmin();
        $res = $this->getJson('/api/v1/admin/badges')->assertOk();
        $this->assertCount(21, $res->json('data'));
        $this->assertContains('total_team', array_column($res->json('rules'), 'key'));
        $this->assertContains('crown', $res->json('icons'));

        $silver = Badge::query()->where('key', 'silver')->firstOrFail();
        $this->putJson("/api/v1/admin/badges/{$silver->id}", ['name' => 'Silver star', 'icon' => 'star', 'color' => '#123456', 'threshold' => 8])
            ->assertOk()->assertJsonPath('data.name', 'Silver star')->assertJsonPath('data.threshold', 8);
        $this->putJson("/api/v1/admin/badges/{$silver->id}", ['color' => 'red'])->assertJsonValidationErrors('color');
        $this->putJson("/api/v1/admin/badges/{$silver->id}", ['icon' => 'skull'])->assertJsonValidationErrors('icon');

        $new = $this->postJson('/api/v1/admin/badges', ['name' => 'Twenty shopper', 'icon' => 'gift', 'color' => '#3f8a4e', 'rule' => 'orders_count', 'threshold' => 20])
            ->assertCreated()->assertJsonPath('data.key', 'twenty_shopper');
        $this->postJson('/api/v1/admin/badges', ['name' => 'X', 'icon' => 'gift', 'color' => '#3f8a4e', 'rule' => 'made_up', 'threshold' => 1])
            ->assertJsonValidationErrors('rule');
        $this->assertDatabaseHas('activity_log', ['action' => 'badge.created']);

        // Switched off: not shown and not awarded.
        $bronze = Badge::query()->where('key', 'bronze')->firstOrFail();
        $this->putJson("/api/v1/admin/badges/{$bronze->id}", ['is_active' => false])->assertOk();
        $this->app['auth']->forgetGuards();
        $me = $this->member(null, 'Abdi');
        foreach (range(1, 3) as $i) {
            $this->member($me, "F$i");
        }
        $this->engine()->evaluate($me);
        $this->assertNotContains('bronze', $this->keys($me));
        $this->assertNull(collect($this->engine()->progress($me))->firstWhere('key', 'bronze'));

        // Held badges can't be deleted (switch off instead); unused ones can.
        $this->superAdmin();
        $held = Badge::query()->where('key', 'early_supporter')->firstOrFail();
        $this->deleteJson("/api/v1/admin/badges/{$held->id}")->assertJsonValidationErrors('badge');
        $this->deleteJson('/api/v1/admin/badges/'.$new->json('data.id'))->assertNoContent();
    }

    public function test_only_super_admin_and_managers_with_settings_can_edit(): void
    {
        $staff = User::factory()->role('fulfilment')->create();
        $staff->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($staff, 'web');
        $this->getJson('/api/v1/admin/badges')->assertForbidden();
        $this->postJson('/api/v1/admin/badges', [])->assertForbidden();
    }

    public function test_badges_switched_off_means_nothing_is_awarded_or_shown(): void
    {
        Features::set('badges', false);
        $me = $this->member(null, 'Abdi');
        $this->assertSame([], $this->engine()->evaluate($me));
        $this->actingAs($me, 'web');
        $this->get('/account/badges')->assertNotFound();
        $this->get('/account')->assertOk()->assertDontSee('Badges (');
    }
}
