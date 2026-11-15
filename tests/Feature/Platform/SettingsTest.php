<?php

namespace Tests\Feature\Platform;

use App\Models\ActivityLog;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_match_the_brief(): void
    {
        $this->assertSame(500_000, Settings::get('membership.price'));          // 5,000 ETB
        $this->assertSame(400_000, Settings::get('signals.guest_price'));       // 4,000 ETB
        $this->assertSame(1_500, Settings::get('pricing.member_discount_bps')); // 15%
        $this->assertSame(300_000, Settings::get('shipping.member_free_over')); // 3,000 ETB
        $this->assertSame(250, Settings::get('catalog.min_price_cost_ratio'));  // 2.5×
        $this->assertSame(1_000, Settings::get('referral.rate.membership.l1'));
        $this->assertSame(100_000, Settings::get('wallet.cashout_min'));
        $this->assertSame(14, Settings::get('referral.pending_days'));
    }

    public function test_set_casts_validates_and_logs_with_reason(): void
    {
        Settings::set('membership.price', '550000', null, 'New price');
        $this->assertSame(550_000, Settings::get('membership.price'));
        $log = ActivityLog::query()->where('action', 'setting.updated')->firstOrFail();
        $this->assertSame('New price', $log->reason);
        // assertEquals: MySQL 8 may reorder JSON object keys.
        $this->assertEquals(['key' => 'membership.price', 'from' => 500_000, 'to' => 550_000], $log->properties);
    }

    public function test_invalid_values_are_rejected(): void
    {
        foreach ([['pricing.member_discount_bps', 10_001], ['membership.price', -1], ['membership.price', 12.5], ['site.name', str_repeat('x', 300)]] as [$k, $v]) {
            try {
                Settings::set($k, $v);
                $this->fail("$k accepted invalid value");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_admin_bulk_update_is_all_or_nothing(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/v1/admin/settings', ['settings' => ['membership.price' => 600_000, 'pricing.member_discount_bps' => 99_999]])
            ->assertUnprocessable();
        $this->assertSame(500_000, Settings::get('membership.price'));

        $this->putJson('/api/v1/admin/settings', ['settings' => ['membership.price' => 600_000], 'reason' => 'test'])
            ->assertOk()->assertJsonFragment(['key' => 'membership.price', 'value' => 600_000]);
    }
}
