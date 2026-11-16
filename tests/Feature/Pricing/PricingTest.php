<?php

namespace Tests\Feature\Pricing;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Pricing\PriceCalculator;
use App\Pricing\PricingException;
use App\Pricing\ProfitCheck;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    private PriceCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = app(PriceCalculator::class);
    }

    /** @return list<array{0: Product, 1: null, 2: int}> */
    private function items(int $price, int $qty = 1): array
    {
        return [[Product::factory()->create(['price' => $price, 'stock' => 50])->load('variants'), null, $qty]];
    }

    public function test_guest_pays_full_price_member_gets_15_percent_off(): void
    {
        $items = $this->items(200_000, 2);
        $guest = $this->calc->quote($items, false);
        $member = $this->calc->quote($items, true);

        $this->assertSame(400_000, $guest->total());
        $this->assertSame(0, $guest->memberDiscount);
        $this->assertSame(340_000, $member->total());           // 2 × 1,700
        $this->assertSame(60_000, $member->memberDiscount);
        $this->assertSame(170_000, $guest->lines[0]->memberUnitPrice); // shown as "Member price"
    }

    public function test_member_discount_rounds_half_up_per_unit(): void
    {
        $this->assertSame(85, $this->calc->memberPrice(100));      // 15 off
        $this->assertSame(283, $this->calc->memberPrice(333));     // 49.95 → 50 off
    }

    public function test_member_discount_switch_and_rate_are_editable(): void
    {
        Settings::set('pricing.member_discount_bps', 2_000);
        $this->assertSame(80_000, $this->calc->memberPrice(100_000));
        Features::set('membership.member_discount', false);
        $this->assertSame(100_000, $this->calc->memberPrice(100_000));
    }

    public function test_free_shipping_for_members_over_3000(): void
    {
        $under = $this->calc->quote($this->items(350_000), true, shipping: 25_000);  // member 2,975 < 3,000
        $over = $this->calc->quote($this->items(360_000), true, shipping: 25_000);   // member 3,060
        $guest = $this->calc->quote($this->items(500_000), false, shipping: 25_000);

        $this->assertSame(25_000, $under->shippingPayable());
        $this->assertSame(0, $over->shippingPayable());
        $this->assertSame(306_000, $over->total());
        $this->assertSame(25_000, $guest->shippingPayable());
    }

    public function test_threshold_is_inclusive_and_editable(): void
    {
        $exact = $this->calc->quote($this->items(300_000), true, shipping: 10_000); // 2,550
        $this->assertSame(10_000, $exact->shippingPayable());
        Settings::set('shipping.member_free_over', 255_000);
        $this->assertSame(0, $this->calc->quote($this->items(300_000), true, shipping: 10_000)->shippingPayable());
    }

    public function test_join_and_save_preview_for_guests(): void
    {
        $q = $this->calc->quote($this->items(400_000), false, shipping: 20_000);
        // 15% of 4,000 = 600 + free shipping 200 (member goods 3,400 ≥ 3,000)
        $this->assertSame(80_000, $q->memberSavingsPreview);

        Features::set('membership', false);
        $this->assertSame(0, $this->calc->quote($this->items(400_000), false, shipping: 20_000)->memberSavingsPreview);
    }

    public function test_percent_and_fixed_coupons_apply_after_member_discount(): void
    {
        Coupon::query()->create(['code' => 'save10', 'type' => 'percent', 'value' => 1_000]);
        Coupon::query()->create(['code' => 'GIFT500', 'type' => 'fixed', 'value' => 50_000]);

        $q = $this->calc->quote($this->items(200_000), true, 'SAVE10');
        $this->assertSame(17_000, $q->couponDiscount);   // 10% of 1,700
        $this->assertSame(153_000, $q->total());

        $noCost = Product::factory()->create(['price' => 20_000, 'stock' => 50, 'cost_materials' => 0, 'cost_labour' => 0, 'cost_packaging' => 0])->load('variants');
        $f = $this->calc->quote([[$noCost, null, 1]], false, 'gift500');
        $this->assertSame(20_000, $f->couponDiscount);   // never more than the goods
        $this->assertSame(0, $f->total());
    }

    public function test_a_coupon_never_takes_an_order_below_its_cost(): void
    {
        Coupon::query()->create(['code' => 'HALF', 'type' => 'percent', 'value' => 5_000]);
        // 1,000 ETB, cost 600 ETB: members pay 850; team earnings (up to 10%) and the fee must still be covered.
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 50, 'cost_materials' => 60_000, 'cost_labour' => 0, 'cost_packaging' => 0])->load('variants');
        $q = $this->calc->quote([[$p, null, 2]], true, 'HALF');
        $this->assertTrue($q->couponCapped);
        $floor = $this->calc->lossFloor($q->lines);
        $this->assertSame($floor, $q->merchandiseTotal());
        $bps = app(ProfitCheck::class)->maxProductCommissionBps($p) + Settings::int('payments.fee_bps');
        $this->assertGreaterThanOrEqual(120_000, $q->merchandiseTotal() - intdiv($q->merchandiseTotal() * $bps, 10_000) - 3, 'covers the cost of 2');
        $this->assertTrue($q->toArray()['coupon_capped']);

        // Cheap coupon on a high-margin product: unchanged.
        $rich = Product::factory()->create(['price' => 100_000, 'stock' => 50, 'cost_materials' => 10_000, 'cost_labour' => 0, 'cost_packaging' => 0])->load('variants');
        $this->assertSame(42_500, $this->calc->quote([[$rich, null, 1]], true, 'HALF')->couponDiscount);
        $this->assertFalse($this->calc->quote([[$rich, null, 1]], true, 'HALF')->couponCapped);

        // Switch off: the coupon applies in full (your choice).
        Features::set('shop.coupon_floor', false);
        $this->assertSame(85_000, $this->calc->quote([[$p, null, 2]], true, 'HALF')->couponDiscount);
    }

    public function test_free_delivery_never_makes_an_order_lose_money(): void
    {
        Settings::set('payments.fee_bps', 150);
        Settings::set('shipping.member_free_over', 300_000);
        // 4,000 ETB, cost 2,600: a member pays 3,400 and gets free delivery.
        $p = Product::factory()->create(['price' => 400_000, 'stock' => 9, 'weight_grams' => 800, 'cost_materials' => 260_000, 'cost_labour' => 0, 'cost_packaging' => 0])->load('variants');

        // Near: the courier costs 120 ETB, free delivery is fine.
        $near = $this->calc->quote([[$p, null, 1]], true, null, 15_000, null, true, [], 12_000);
        $this->assertSame(15_000, $near->shippingDiscount);
        $this->assertFalse($near->shippingCapped);

        // Far: the courier costs 600 ETB, more than what is left: part of the delivery is charged.
        $far = $this->calc->quote([[$p, null, 1]], true, null, 40_000, null, true, [], 60_000);
        $this->assertTrue($far->shippingCapped);
        $this->assertTrue($far->toArray()['shipping_capped']);
        $this->assertLessThan(40_000, $far->shippingDiscount);
        $charged = $far->shipping - $far->shippingDiscount;
        $goods = $far->merchandiseTotal();
        $team = intdiv($goods * app(ProfitCheck::class)->maxProductCommissionBps($p), 10_000);
        $fee = intdiv(($goods + $charged) * 150 + 9_999, 10_000);
        $this->assertGreaterThanOrEqual(0, $goods + $charged - 260_000 - $team - $fee - 60_000, 'the order still pays for everything');

        // Switch off: free delivery is always free (your choice).
        Features::set('shop.coupon_floor', false);
        $this->assertSame(40_000, $this->calc->quote([[$p, null, 1]], true, null, 40_000, null, true, [], 60_000)->shippingDiscount);
    }

    public function test_the_price_check_counts_the_courier_when_one_item_already_ships_free(): void
    {
        Settings::set('shipping.member_free_over', 300_000);
        ShippingZone::query()->create(['name' => 'Near', 'cities' => ['Adama'], 'base_fee' => 15_000, 'courier_cost' => 12_000, 'is_default' => true]);
        ShippingZone::query()->create(['name' => 'Far', 'cities' => ['Gambela'], 'base_fee' => 40_000, 'courier_cost' => 60_000]);
        $cheap = Product::factory()->create(['price' => 100_000, 'weight_grams' => 500]);
        $dear = Product::factory()->create(['price' => 400_000, 'weight_grams' => 500]);
        $this->assertSame(0, app(ProfitCheck::class)->worstCase($cheap)['courier'], 'no free delivery for one cheap item');
        $w = app(ProfitCheck::class)->worstCase($dear);
        $this->assertSame(60_000, $w['courier'], 'the most any city costs');
        $this->assertSame($w['member_price'] - $w['commission'] - $w['payment_fee'] - $w['cost'] - 60_000, $w['profit']);

        // The admin is warned where the courier costs more than customers pay for delivery.
        $this->actingAsAdmin();
        $zones = collect($this->getJson('/api/v1/admin/shipping-zones')->assertOk()->json('data'))->keyBy('name');
        $this->assertNull($zones['Near']['courier_warning']);
        $this->assertStringContainsString('costs more than customers pay', (string) $zones['Far']['courier_warning']);
    }

    public function test_coupon_rules(): void
    {
        Coupon::query()->create(['code' => 'OLD', 'type' => 'fixed', 'value' => 100, 'ends_at' => now()->subDay()]);
        Coupon::query()->create(['code' => 'MEMB', 'type' => 'fixed', 'value' => 100, 'members_only' => true]);
        Coupon::query()->create(['code' => 'MIN', 'type' => 'fixed', 'value' => 100, 'min_subtotal' => 500_000]);
        $usedUp = Coupon::query()->create(['code' => 'USED', 'type' => 'fixed', 'value' => 100, 'max_uses' => 1]);
        $usedUp->forceFill(['used_count' => 1])->save();
        Coupon::query()->create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 100, 'max_uses_per_customer' => 1]);

        foreach (['OLD', 'MEMB', 'MIN', 'USED', 'NOPE'] as $code) {
            try {
                $this->calc->quote($this->items(100_000), false, $code);
                $this->fail("$code accepted");
            } catch (PricingException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(PricingException::class);
        $this->calc->quote($this->items(100_000), false, 'ONCE', customerUses: fn () => 1);
    }

    public function test_coupons_switch_off(): void
    {
        Coupon::query()->create(['code' => 'X', 'type' => 'fixed', 'value' => 100]);
        Features::set('shop.coupons', false);
        $this->expectException(PricingException::class);
        $this->calc->quote($this->items(100_000), false, 'X');
    }

    public function test_stock_variants_and_members_only_are_enforced(): void
    {
        $p = Product::factory()->create(['stock' => 1]);
        try {
            $this->calc->quote([[$p->load('variants'), null, 2]], false);
            $this->fail('oversold');
        } catch (PricingException $e) {
            $this->assertStringContainsString('Only 1 left', $e->getMessage());
        }

        $v = $p->variants()->create(['name' => 'Red', 'stock' => 3]);
        try {
            $this->calc->quote([[$p->fresh('variants'), null, 1]], false);
            $this->fail('variant not required');
        } catch (PricingException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(3, $this->calc->quote([[$p->fresh('variants'), $v, 3]], false)->lines[0]->quantity);

        $motm = Product::factory()->create(['is_members_only' => true])->load('variants');
        $this->assertSame(1, count($this->calc->quote([[$motm, null, 1]], true)->lines));
        $this->expectException(PricingException::class);
        $this->calc->quote([[$motm, null, 1]], false);
    }

    public function test_made_to_order_ignores_stock(): void
    {
        $p = Product::factory()->madeToOrder()->create()->load('variants');
        $this->assertSame(5, $this->calc->quote([[$p, null, 5]], false)->lines[0]->quantity);
    }

    public function test_profit_check_warns_when_member_order_loses_money(): void
    {
        // cost 750; member price 1,700 − 10% commission (6+3+1) 170 − fee 3.5% 59.5 → profitable
        $ok = Product::factory()->create(['price' => 200_000])->load('variants');
        $this->assertSame([], app(ProfitCheck::class)->warnings($ok));
        $w = app(ProfitCheck::class)->worstCase($ok);
        $this->assertSame(170_000, $w['member_price']);
        $this->assertSame(17_000, $w['commission']);
        $this->assertSame(5_950, $w['payment_fee']);
        $this->assertSame(72_050, $w['profit']);

        // Cost 1,000, price 1,100: member 935 − 93.5 − 32.7 − 1,000 < 0
        $bad = Product::factory()->create(['price' => 110_000, 'cost_materials' => 100_000, 'cost_labour' => 0, 'cost_packaging' => 0])->load('variants');
        $codes = array_column(app(ProfitCheck::class)->warnings($bad), 'code');
        $this->assertContains('price_below_cost_ratio', $codes);
        $this->assertContains('unprofitable_member_order', $codes);
    }

    public function test_profit_check_follows_switched_off_levels(): void
    {
        Features::set('referral.level2', false);
        Features::set('referral.level3', false);
        $this->assertSame(600, app(ProfitCheck::class)->maxProductCommissionBps());
        Features::set('referral', false);
        $this->assertSame(0, app(ProfitCheck::class)->maxProductCommissionBps());
    }

    public function test_api_shows_member_price_next_to_guest_price(): void
    {
        Product::factory()->create(['slug' => 'mesob', 'price' => 200_000]);
        $this->getJson('/api/v1/products/mesob')->assertJsonPath('data.price', 200_000)
            ->assertJsonPath('data.member_price', 170_000)->assertJsonPath('data.member_saving', 30_000);
    }

    public function test_admin_coupon_crud(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/coupons', ['code' => 'timket', 'type' => 'percent', 'value' => 20_000])->assertUnprocessable();
        $id = $this->postJson('/api/v1/admin/coupons', ['code' => 'timket', 'type' => 'percent', 'value' => 1_500])
            ->assertCreated()->assertJsonPath('data.code', 'TIMKET')->json('data.id');
        $this->putJson("/api/v1/admin/coupons/$id", ['code' => 'TIMKET', 'type' => 'fixed', 'value' => 10_000, 'is_active' => false])->assertOk();
        $this->assertFalse(Coupon::query()->findOrFail($id)->is_active);
    }

    public function test_admin_product_shows_profit_breakdown(): void
    {
        $this->actingAsAdmin();
        $p = Product::factory()->create(['price' => 200_000]);
        $this->getJson("/api/v1/admin/products/{$p->id}")->assertJsonPath('data.profit.profit', 72_050);
    }
}
