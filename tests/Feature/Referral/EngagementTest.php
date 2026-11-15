<?php

namespace Tests\Feature\Referral;

use App\Badges\BadgeEngine;
use App\Models\Product;
use App\Support\Features;
use Illuminate\Support\Facades\Cache;

class EngagementTest extends ReferralTestCase
{
    public function test_top_referrers_first_name_and_badge_only(): void
    {
        $a = $this->member(null, 'Abdi');
        $a->forceFill(['last_name' => 'Secret'])->save();
        $b = $this->member(null, 'Bontu');
        foreach (range(1, 3) as $i) {
            $this->member($a, "A$i");
        }
        $this->member($b, 'B1');
        app(BadgeEngine::class)->evaluate($a);
        Cache::flush();

        $rows = $this->getJson('/api/v1/referral/top')->assertOk()->json('data');
        $this->assertSame([['rank' => 1, 'name' => 'Abdi', 'badge' => ['name' => 'Bronze', 'icon' => 'medal', 'color' => '#b0703c'], 'count' => 3], ['rank' => 2, 'name' => 'Bontu', 'badge' => null, 'count' => 1]], $rows);
        $this->assertStringNotContainsString('Secret', (string) json_encode($rows));

        Features::set('top_referrers', false);
        $this->getJson('/api/v1/referral/top')->assertNotFound();
    }

    public function test_dashboard_has_qr_share_links_badge_and_renewal(): void
    {
        $me = $this->member(null, 'Abdi');
        $json = $this->actingAs($me, 'sanctum')->getJson('/api/v1/referral')->assertOk()->json('data');
        $this->assertStringStartsWith('https://t.me/share/url?url=', $json['share']['telegram']);
        $this->assertStringStartsWith('https://wa.me/?text=', $json['share']['whatsapp']);
        $this->assertNotNull($json['renews_at']);
        $this->assertArrayHasKey('badge', $json);

        $svg = $this->get('/api/v1/referral/qr.svg')->assertOk();
        $this->assertStringContainsString('<svg', (string) $svg->getContent());
    }

    public function test_members_only_and_early_access_filters(): void
    {
        Product::factory()->create(['is_members_only' => true]);
        Product::factory()->create();
        $this->getJson('/api/v1/products?members_only=1')->assertJsonCount(1, 'data');
    }
}
