<?php

namespace Tests\Feature\Referral;

use App\Models\User;

class DashboardTest extends ReferralTestCase
{
    public function test_dashboard_numbers_come_from_ledger_and_orders(): void
    {
        [, , $l1] = $this->chain();
        $order = $this->buyProducts(200_000, null, $l1->referral_code);   // goods 2,000 + 200 shipping, cost 750
        $this->payMembership(User::factory()->create(['referrer_id' => $l1->id])); // 5,000

        $this->actingAsAdmin();
        $d = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        $this->assertSame(220_000, $d['revenue']['products']);
        $this->assertSame(500_000, $d['revenue']['memberships']);
        $this->assertSame(720_000, $d['revenue']['total']);
        $this->assertSame(75_000, $d['costs']['products']);
        $this->assertSame(20_000 + 85_000, $d['costs']['commissions']);  // (6+3+1)% of 2,000 + (10+5+2)% of 5,000
        $this->assertSame(720_000 - 75_000 - 105_000, $d['gross_profit']);
        $this->assertSame(105_000, $d['commissions']['pending']);
        $this->assertSame(1, $d['orders']['paid']);
        $this->assertSame(20_000, $d['shipping']['charged']);
        $this->assertGreaterThanOrEqual(4, $d['membership']['active']);   // chain (3) + buyer
        $this->assertSame($d['membership']['active'] * 500_000, $d['membership']['mrr']);
        $this->assertSame($order->items()->first()->name, $d['top_products'][0]['name']);
        $this->assertCount(30, $d['daily']);
    }

    public function test_status_page_and_permission(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/status')->assertOk()->assertJsonStructure(['data' => ['scheduler_ok', 'queue_waiting', 'integrations']]);
        $this->actingAsAdmin('fulfilment');
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }
}
