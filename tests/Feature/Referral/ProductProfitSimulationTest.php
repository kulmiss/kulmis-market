<?php

namespace Tests\Feature\Referral;

use App\Models\Commission;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Support\Features;
use App\Support\Settings;
use App\Support\Totp;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * "Do I ever lose money on a product order?" Random products and costs,
 * flash sales and bundles accepted by the admin's own profit check (never
 * "accept loss"), coupons up to half price, members and guests through
 * referral links, all 3 team levels: then, for every paid order,
 *   goods + extras + delivery paid − product cost − team earnings
 *   − payment fee (on everything paid) − what the courier charges ≥ 0.
 * Couriers cost different amounts per city; members get free delivery over
 * the threshold, so the shop pays the courier on those orders.
 */
class ProductProfitSimulationTest extends ReferralTestCase
{
    /** @return array<string, array{0:int}> */
    public static function seeds(): array
    {
        return ['seed 11' => [11], 'seed 22' => [22], 'seed 33' => [33]];
    }

    #[DataProvider('seeds')]
    public function test_no_product_order_loses_money(int $seed): void
    {
        mt_srand($seed);
        Settings::set('payments.fee_bps', 150); // a payment fee makes it harder
        $cities = ['Addis Ababa' => [15_000, 12_000], 'Adama' => [25_000, null], 'Gambela' => [40_000, 38_000]];
        foreach ($cities as $city => [$fee, $courier]) {
            ShippingZone::query()->create(['name' => $city, 'cities' => [$city], 'base_fee' => $fee, 'included_grams' => 1000,
                'per_kg_fee' => 5_000, 'courier_cost' => $courier, 'courier_per_kg' => $courier ? 6_000 : null]);
        }
        foreach (['shop.extras.gift_wrap', 'shop.extras.express', 'shop.extras.insurance'] as $f) {
            Features::set($f, true);
        }
        // A team: 25 members, up to 3 levels and more.
        $members = [];
        foreach (range(1, 25) as $i) {
            $members[] = $this->member($members && mt_rand(1, 100) <= 80 ? $members[array_rand($members)] : null, "M$i");
        }
        // Products: price 300–3,000 ETB, cost 20–45% of the price.
        $products = [];
        foreach (range(1, 14) as $i) {
            $price = mt_rand(30, 300) * 1_000;
            $products[] = Product::factory()->create(['price' => $price, 'stock' => 999, 'weight_grams' => 400,
                'cost_materials' => intdiv($price * mt_rand(20, 45), 100), 'cost_labour' => 0, 'cost_packaging' => 0]);
        }
        // Promotions the admin tries; the profit check refuses the ones that would lose money.
        $this->actingAs($this->admin, 'web');
        $this->admin->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $accepted = ['flash' => 0, 'bundle' => 0, 'refused' => 0];
        foreach (array_rand($products, 6) as $k) {
            $p = $products[$k];
            $status = $this->postJson('/api/v1/admin/flash-sales', ['product_id' => $p->id, 'price' => intdiv($p->price * mt_rand(45, 95), 100),
                'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String()])->getStatusCode();
            $status === 201 ? $accepted['flash']++ : $accepted['refused']++;
        }
        foreach (range(1, 4) as $i) {
            [$a, $b] = array_map(fn ($k) => $products[$k], array_rand($products, 2));
            $status = $this->postJson('/api/v1/admin/bundles', ['name' => "Set $i", 'price' => intdiv(($a->price + $b->price) * mt_rand(55, 95), 100), 'is_active' => true,
                'items' => [['product_id' => $a->id, 'quantity' => 1], ['product_id' => $b->id, 'quantity' => 1]]])->getStatusCode();
            $status === 201 ? $accepted['bundle']++ : $accepted['refused']++;
        }
        // A clearly unsafe one (40% of the price on the product with the highest cost) is always refused.
        $dear = collect($products)->sortByDesc(fn (Product $p) => $p->totalCost() * 10_000 / $p->price)->first();
        $this->postJson('/api/v1/admin/flash-sales', ['product_id' => $dear->id, 'price' => intdiv($dear->price * 40, 100),
            'starts_at' => now()->addDays(3)->toIso8601String(), 'ends_at' => now()->addDays(4)->toIso8601String()])->assertJsonValidationErrors('profit');
        $this->app['auth']->forgetGuards();
        $this->assertGreaterThan(0, $accepted['flash'] + $accepted['bundle'], 'some promotions are safe');
        foreach ([['TEN', 'percent', 1_000], ['THIRTY', 'percent', 3_000], ['HALF', 'percent', 5_000], ['MINUS300', 'fixed', 30_000]] as [$code, $type, $value]) {
            Coupon::query()->create(['code' => $code, 'type' => $type, 'value' => $value]);
        }

        // 90 random orders.
        $placed = 0;
        foreach (range(1, 90) as $n) {
            $this->travel(7)->seconds(); // real people, not a burst (the checkout allows 10 a minute)
            $buyer = mt_rand(1, 100) <= 45 ? $members[array_rand($members)] : null;
            if ($buyer) {
                $this->actingAs($buyer, 'sanctum');
            }
            $token = null;
            foreach ((array) array_rand($products, mt_rand(1, 3)) as $k) {
                $res = $this->withHeaders($token ? ['X-Cart-Token' => $token] : [])->postJson('/api/v1/cart/items', ['product_id' => $products[$k]->id, 'quantity' => mt_rand(1, 2)]);
                $token ??= $res->json('token');
            }
            $h = ['X-Cart-Token' => $token];
            if (mt_rand(1, 100) <= 70) {
                $this->withHeaders($h)->putJson('/api/v1/cart/coupon', ['code' => ['TEN', 'THIRTY', 'HALF', 'MINUS300'][mt_rand(0, 3)]]);
            }
            if (mt_rand(1, 100) <= 30) {
                $this->withHeaders($h)->putJson('/api/v1/cart/extras', ['extras' => ['gift_wrap']]);
            }
            $res = $this->withHeaders($h)->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(), 'first_name' => 'Buyer',
                'phone' => $buyer?->phone ?? '09'.mt_rand(10_000_000, 99_999_999), 'city' => array_rand($cities), 'address' => 'x',
                'referral_code' => $buyer ? null : $members[array_rand($members)]->referral_code]);
            if ($res->getStatusCode() === 201) {
                $order = Order::query()->latest('id')->firstOrFail();
                if ($order->amountDue() > 0) {
                    $this->approve($order);
                }
                $placed++;
            }
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
        }
        $this->assertGreaterThan(60, $placed, 'most orders went through');

        $worst = PHP_INT_MAX;
        $paid = Order::query()->whereIn('status', [Order::PAID])->with('items.product')->get();
        $this->assertGreaterThan(50, $paid->count());
        foreach ($paid as $o) {
            $cost = (int) $o->items->sum(fn ($i) => $i->product->totalCost() * $i->quantity);
            $team = (int) Commission::query()->where('source_type', $o->getMorphClass())->where('source_id', $o->id)->sum('amount');
            $delivery = $o->shipping - $o->shipping_discount;
            $fee = intdiv($o->total * Settings::int('payments.fee_bps') + 9_999, 10_000);
            $profit = $o->merchandise_total + $o->extras_total + $delivery - $cost - $team - $fee - $o->courier_cost;
            $this->assertGreaterThan(0, $o->courier_cost, 'every order knows what delivery cost');
            $worst = min($worst, $profit);
            $this->assertGreaterThanOrEqual(0, $profit, "order {$o->number} loses ".-$profit.' santim (goods '.$o->merchandise_total.", delivery paid $delivery, courier {$o->courier_cost}, cost $cost, team $team, fee $fee)");
        }
        $this->assertGreaterThan(0, Commission::query()->where('type', 'products')->where('amount', '>', 0)->count(), 'team earnings were paid');
        $this->assertGreaterThan(0, Order::query()->where('coupon_discount', '>', 0)->count(), 'coupons were used');
        $this->assertGreaterThan(0, Order::query()->where('status', Order::PAID)->where('shipping_discount', '>', 0)->count(), 'some orders shipped free');
        $this->assertGreaterThanOrEqual(0, $worst);
    }
}
