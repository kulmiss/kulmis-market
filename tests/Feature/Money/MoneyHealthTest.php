<?php

namespace Tests\Feature\Money;

use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Reports\MoneyHealth;
use App\Reports\WhatIf;
use Tests\Feature\Referral\ReferralTestCase;

class MoneyHealthTest extends ReferralTestCase
{
    public function test_the_page_shows_money_in_paid_out_kept_and_owed_with_a_green_light(): void
    {
        [$l3, $l2, $l1] = $this->chain();
        $this->payMembership($this->member($l1, 'Dawit'));
        $this->actingAsAdmin();
        $d = $this->getJson('/api/v1/admin/money-health?period=month')->assertOk()->json('data');
        // The chain's own memberships came from the factory (no payment); Dawit paid 5,000: 500 + 250 + 100 + 250 first-payment bonus.
        $this->assertSame(500_000, $d['money_in']);
        $this->assertSame(110_000, $d['paid_to_members']);
        $this->assertSame(390_000, $d['kept']);
        $this->assertEquals(78.0, $d['kept_percent']);
        $this->assertSame(110_000, $d['on_the_way']);
        $this->assertSame('green', $d['light']);
        $this->assertCount(6, $d['months']);
        $this->actingAsAdmin('fulfilment');
        $this->getJson('/api/v1/admin/money-health')->assertForbidden();
    }

    public function test_red_when_members_are_owed_more_than_the_shop_received(): void
    {
        $u = $this->member();
        $this->checkMoney = false; // on purpose: money in a wallet that never came in
        app(Ledger::class)->post('manual.adjustment', [[Account::ADJUSTMENTS, 900_000], [app(Ledger::class)->userAccount($u->id, Wallet::AVAILABLE), -900_000]], reason: 'test');
        $h = app(MoneyHealth::class)->summary(now()->startOfMonth(), now());
        $this->assertSame('red', $h['light']);
        $this->assertContains('Members are owed more than the shop has received.', $h['why']);
    }

    public function test_what_if_uses_the_real_settings(): void
    {
        $w = app(WhatIf::class)->forShop(100, 3);
        // 100 → 300 → 900 → 2,700 people; 4,000 × 5,000 ETB.
        $this->assertSame(4_000, $w['members']);
        $this->assertSame(2_000_000_000, $w['money_in']);
        $this->assertSame((300 * 500 + 900 * 750 + 2_700 * 850) * 100, $w['team_earnings']);
        $this->assertSame(1_300 * 200 * 100, $w['badge_bonus'], 'Bronze (3 invites): 200 ETB for everyone who has 3');
        $this->assertSame(83.1, $w['kept_percent']);
        $this->assertSame(665_000, $w['one_member_earns'], '3 × 500 + 9 × 250 + 27 × 100 + 200 Bronze');
        $this->assertSame(['level1' => 150_000, 'level2' => 0, 'level3' => 0, 'badge_bonus' => 20_000, 'per_month' => 170_000, 'first_payment_bonus' => 75_000],
            app(WhatIf::class)->forMember(3));
        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/money-health/what-if?members=100&invites=3')->assertOk()->assertJsonPath('data.members', 4_000);
    }
}
