<?php

namespace Tests\Feature\Referral;

use App\Ledger\Wallet;
use App\Models\User;
use App\Support\Features;

class AttributionTest extends ReferralTestCase
{
    private function register(array $extra = [], array $cookies = []): User
    {
        $res = $this->withCredentials()->withCookies($cookies)->postJson('/api/v1/auth/register', array_merge([
            'first_name' => 'New', 'last_name' => 'Person', 'phone' => '09'.random_int(10_000_000, 99_999_999), 'password' => 'abc-12345-xyz-go',
        ], $extra))->assertCreated();

        return User::query()->findOrFail($res->json('user.id'));
    }

    public function test_every_customer_gets_a_short_unique_code(): void
    {
        $u = $this->register(['first_name' => 'Abdi']);
        $this->assertMatchesRegularExpression('/^ABDI\d{2,4}$/', (string) $u->referral_code);
    }

    public function test_link_sets_30_day_cookie_with_only_the_code_and_latest_wins(): void
    {
        $a = $this->member(null, 'Alem');
        $b = $this->member(null, 'Biruk');

        $res = $this->get('/r/'.strtolower((string) $a->referral_code))->assertRedirect();
        $cookie = collect($res->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'km_ref');
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $cookie->getExpiresTime(), 120);
        $this->assertTrue($cookie->isHttpOnly());

        // Latest link wins.
        $res2 = $this->get('/?ref='.$b->referral_code);
        $latest = collect($res2->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'km_ref');
        $this->assertNotNull($latest);

        $u = $this->register([], ['km_ref' => (string) $b->referral_code]);
        $this->assertSame($b->id, $u->referrer_id);
    }

    public function test_unknown_code_in_link_sets_nothing(): void
    {
        $res = $this->get('/r/NOPE99');
        $this->assertNull(collect($res->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'km_ref'));
    }

    public function test_code_field_is_the_backup_and_wins_over_cookie(): void
    {
        $a = $this->member(null, 'Alem');
        $b = $this->member(null, 'Biruk');
        $u = $this->register(['referral_code' => strtolower((string) $a->referral_code)], ['km_ref' => (string) $b->referral_code]);
        $this->assertSame($a->id, $u->referrer_id);

        $this->postJson('/api/v1/auth/register', ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '0911999888', 'password' => 'abc-12345-xyz-go',
            'referral_code' => 'DOESNOTEXIST'])->assertUnprocessable()->assertJsonValidationErrors('referral_code');
    }

    public function test_referrer_is_locked_after_joining(): void
    {
        $a = $this->member(null, 'Alem');
        $b = $this->member(null, 'Biruk');
        $u = $this->register(['referral_code' => $a->referral_code]);

        // A later link/code does not change it: orders by this user credit A.
        $this->actingAs($u->fresh(), 'sanctum');
        $order = $this->buyProducts(100_000, $u->fresh(), $b->referral_code);
        $this->assertNull($order->referrer_user_id);
        $this->assertSame(6_000, Wallet::for($a)->pending());
        $this->assertSame(0, Wallet::for($b)->pending());
    }

    public function test_only_super_admin_changes_referrer_with_reason_and_no_loops(): void
    {
        $a = $this->member(null, 'Alem');
        $b = $this->member($a, 'Biruk');
        $c = $this->member($b, 'Chala');

        $this->actingAsAdmin('manager');
        $this->postJson("/api/v1/admin/referrals/members/{$c->id}/referrer", ['referrer_id' => $a->id, 'reason' => 'Support ticket 12'])->assertForbidden();

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/referrals/members/{$c->id}/referrer", ['referrer_id' => $a->id])->assertUnprocessable();
        $this->postJson("/api/v1/admin/referrals/members/{$a->id}/referrer", ['referrer_id' => $c->id, 'reason' => 'loop test'])
            ->assertUnprocessable()->assertJsonValidationErrors('referrer_id');
        $this->postJson("/api/v1/admin/referrals/members/{$c->id}/referrer", ['referrer_id' => $a->id, 'reason' => 'Support ticket 12'])->assertOk();
        $this->assertSame($a->id, $c->fresh()->referrer_id);
        $this->assertDatabaseHas('activity_log', ['action' => 'referral.referrer_changed', 'reason' => 'Support ticket 12']);
    }

    public function test_member_can_change_code_once_with_rules(): void
    {
        $m = $this->member(null, 'Abdi');
        $this->actingAs($m, 'sanctum');
        $this->putJson('/api/v1/referral/code', ['code' => 'ab'])->assertUnprocessable();
        $this->putJson('/api/v1/referral/code', ['code' => 'ADMIN1'])->assertUnprocessable();
        $this->putJson('/api/v1/referral/code', ['code' => 'SH1T99'])->assertUnprocessable();
        $other = $this->member(null, 'Kebede');
        $this->putJson('/api/v1/referral/code', ['code' => $other->referral_code])->assertUnprocessable();

        $this->putJson('/api/v1/referral/code', ['code' => 'abdi25'])->assertOk()->assertJsonPath('data.code', 'ABDI25');
        $this->putJson('/api/v1/referral/code', ['code' => 'ABDI26'])->assertUnprocessable();
    }

    public function test_team_view_shows_only_allowed_fields(): void
    {
        $me = $this->member(null, 'Abdi');
        $l1 = $this->member($me, 'Hanna');
        $l1->forceFill(['last_name' => 'Tesfaye'])->save();
        $l2 = $this->member($l1, 'Yared');
        $this->member($l2, 'Zala');
        User::factory()->create(['referrer_id' => $me->id, 'first_name' => 'Inactive']);

        $json = $this->actingAs($me, 'sanctum')->getJson('/api/v1/referral')->assertOk()->json('data');
        $this->assertSame(2, $json['team']['level1_count']);
        $this->assertSame(1, $json['team']['level1_active']);
        $this->assertSame(1, $json['team']['level2_count']);
        $this->assertSame(1, $json['team']['level3_count']);
        $names = array_column($json['team']['level1'], 'name');
        $this->assertContains('Hanna T.', $names);
        $raw = json_encode($json);
        $this->assertStringNotContainsString((string) $l1->phone, (string) $raw);
        $this->assertStringNotContainsString((string) $l1->email, (string) $raw);
        $this->assertStringNotContainsString('Yared', (string) $raw);
        $this->assertSame(['name', 'joined', 'active'], array_keys($json['team']['level1'][0]));
    }

    public function test_referral_switch_off_hides_api_and_ignores_codes(): void
    {
        $a = $this->member(null, 'Alem');
        Features::set('referral', false);
        $this->actingAs($a, 'sanctum')->getJson('/api/v1/referral')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $u = $this->register([], ['km_ref' => (string) $a->referral_code]);
        $this->assertNull($u->referrer_id);
    }

    public function test_admin_tree_and_commissions(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        $this->payMembership(User::factory()->create(['referrer_id' => $l1->id]));
        $this->actingAsAdmin();
        $this->getJson("/api/v1/admin/referrals/tree/{$l3->id}")->assertOk()
            ->assertJsonPath('data.node.children.0.id', $l2->id)->assertJsonPath('data.node.children.0.children.0.id', $l1->id);
        $this->getJson("/api/v1/admin/referrals/tree/{$l1->id}")->assertJsonCount(2, 'data.upline');
        $this->getJson('/api/v1/admin/referrals/commissions?level=1')->assertJsonPath('total', 1);
    }
}
