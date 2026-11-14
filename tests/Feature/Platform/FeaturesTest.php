<?php

namespace Tests\Feature\Platform;

use App\Models\ActivityLog;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_come_from_config(): void
    {
        $this->assertTrue(Features::enabled('shop'));
        $this->assertFalse(Features::enabled('shop.reviews'));
    }

    public function test_child_is_off_when_parent_is_off_without_losing_its_own_value(): void
    {
        Features::set('referral', false);

        $this->assertFalse(Features::enabled('referral.level2'));
        $this->assertTrue(Features::own('referral.level2'));

        Features::set('referral', true);
        $this->assertTrue(Features::enabled('referral.level2'));
    }

    public function test_membership_off_turns_off_referral_and_member_perks(): void
    {
        Features::set('membership', false);
        foreach (['referral', 'referral.level1', 'shop.free_shipping', 'shop.early_access', 'membership.member_discount'] as $key) {
            $this->assertFalse(Features::enabled($key), $key);
        }
        $this->assertTrue(Features::enabled('shop'));
    }

    public function test_feature_middleware_hides_routes_with_404(): void
    {
        Route::middleware('feature:signals')->get('/_t/signals', fn () => 'ok');
        $this->get('/_t/signals')->assertOk();

        Features::set('signals', false);
        $this->get('/_t/signals')->assertNotFound();
    }

    public function test_changes_are_logged(): void
    {
        Features::set('signals', false);
        Features::set('signals', false); // no-op, not logged twice

        $this->assertSame(1, ActivityLog::query()->where('action', 'feature.disabled')->count());
    }

    public function test_unknown_feature_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Features::enabled('nope');
    }

    public function test_admin_overview_and_bulk_update(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/features')->assertOk()
            ->assertJsonFragment(['key' => 'referral.level3', 'enabled' => true, 'effective' => true]);

        $this->putJson('/api/v1/admin/features', ['features' => ['referral' => false]])->assertOk()
            ->assertJsonFragment(['key' => 'referral.level3', 'enabled' => true, 'effective' => false]);

        $this->putJson('/api/v1/admin/features', ['features' => ['bogus' => true]])->assertUnprocessable();
        $this->putJson('/api/v1/admin/features', ['features' => ['signals' => 'yes']])->assertUnprocessable();
    }
}
