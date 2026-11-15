<?php

namespace Tests\Feature\Shop;

use App\Models\Bundle;
use App\Models\Coupon;
use App\Models\FlashSale;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingZone;
use App\Orders\OrderService;
use App\Pricing\PriceCalculator;
use App\Shop\Bundles;
use App\Shop\FlashSales;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Flash sales, bundles and "bought together / you may also like". */
class FlashSalesBundlesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ShippingZone::query()->create(['name' => 'All', 'cities' => [], 'base_fee' => 10_000, 'is_default' => true]);
    }

    private function sale(Product $p, int $price, array $extra = []): FlashSale
    {
        return FlashSale::query()->create(array_merge(['product_id' => $p->id, 'price' => $price,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()], $extra));
    }

    /** @return list<array{0: Product, 1: ?ProductVariant, 2: int}> */
    private function items(Product ...$products): array
    {
        return array_map(fn (Product $p) => [$p->fresh()->load('variants'), null, 1], $products);
    }

    private function checkout(Product $p, int $qty = 1, string $phone = '0911000301'): TestResponse
    {
        $this->flushHeaders();
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'quantity' => $qty])->json('token');

        return $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'A', 'phone' => $phone, 'city' => 'Adama', 'address' => 'x']);
    }

    public function test_flash_price_only_while_live_and_member_price_on_top(): void
    {
        $p = Product::factory()->create(['price' => 200_000]);
        $calc = app(PriceCalculator::class);
        $this->sale($p, 150_000);

        $guest = $calc->quote($this->items($p), false);
        $this->assertSame(150_000, $guest->lines[0]->unitPrice);
        $this->assertSame(200_000, $guest->lines[0]->regularUnitPrice);
        $member = $calc->quote($this->items($p), true);
        $this->assertSame(127_500, $member->lines[0]->unitPrice); // 15% off the sale price

        $this->travel(2)->hours();
        app(FlashSales::class)->forget();
        $this->assertSame(200_000, $calc->quote($this->items($p), false)->lines[0]->unitPrice);
    }

    public function test_flash_sale_switch_off_and_future_sale(): void
    {
        $p = Product::factory()->create(['price' => 200_000]);
        $this->sale($p, 150_000, ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        $calc = app(PriceCalculator::class);
        $this->assertSame(200_000, $calc->quote($this->items($p), false)->lines[0]->unitPrice);

        $this->sale($p, 150_000);
        Features::set('shop.flash_sales', false);
        app(FlashSales::class)->forget();
        $this->assertSame(200_000, $calc->quote($this->items($p), false)->lines[0]->unitPrice);
    }

    public function test_variant_sale_beats_product_sale(): void
    {
        $p = Product::factory()->create(['price' => 200_000]);
        $v = ProductVariant::query()->forceCreate(['product_id' => $p->id, 'name' => 'Large', 'price' => 250_000, 'stock' => 5, 'is_active' => true]);
        $this->sale($p, 180_000);
        $this->sale($p, 190_000, ['product_variant_id' => $v->id]);
        $q = app(PriceCalculator::class)->quote([[$p->fresh()->load('variants'), $v, 1]], false);
        $this->assertSame(190_000, $q->lines[0]->unitPrice);
    }

    public function test_limited_pieces_then_normal_price_and_restock_gives_them_back(): void
    {
        $p = Product::factory()->create(['price' => 200_000, 'stock' => 10]);
        $sale = $this->sale($p, 150_000, ['stock_limit' => 2]);

        // Asking for 3 when only 2 are left at the sale price: normal price (never a mix).
        $this->assertSame(200_000, app(PriceCalculator::class)->quote([[$p->fresh()->load('variants'), null, 3]], false)->lines[0]->unitPrice);

        $this->checkout($p, 2)->assertCreated();
        $first = Order::query()->latest('id')->firstOrFail();
        $this->assertSame(300_000, $first->merchandise_total);
        $this->assertSame(2, $sale->fresh()->sold);
        $this->assertSame($sale->id, $first->items->first()->flash_sale_id);

        app(FlashSales::class)->forget();
        $this->checkout($p, 1, '0911000302')->assertCreated();
        $this->assertSame(200_000, Order::query()->latest('id')->firstOrFail()->merchandise_total);

        app(OrderService::class)->restock($first->load('items'));
        $this->assertSame(0, $sale->fresh()->sold);
    }

    public function test_last_sale_piece_is_sold_once_even_with_a_stale_price(): void
    {
        $p = Product::factory()->create(['price' => 200_000, 'stock' => 10]);
        $sale = $this->sale($p, 150_000, ['stock_limit' => 1]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        app(FlashSales::class)->live(); // this request already "saw" the sale
        DB::table('flash_sales')->where('id', $sale->id)->update(['sold' => 1]); // another checkout took it meanwhile

        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'A', 'phone' => '0911000303', 'city' => 'Adama', 'address' => 'x'])
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'sale price'));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(10, $p->fresh()->stock);
        $this->assertSame(1, $sale->fresh()->sold);
    }

    public function test_product_page_shows_sale_and_countdown(): void
    {
        $p = Product::factory()->create(['price' => 200_000]);
        $this->sale($p, 150_000, ['stock_limit' => 5]);
        $this->get('/shop/'.$p->slug)->assertOk()->assertSee('data-countdown', false)->assertSee('Flash sale')
            ->assertSee('only 5 pieces at this price')->assertSee('"price":"1500.00"', false);
        $this->get('/shop')->assertSee('Sale −25%');
    }

    public function test_bundle_saving_in_cart_member_price_on_top_and_coupon_after(): void
    {
        [$a, $b] = [Product::factory()->create(['price' => 100_000]), Product::factory()->create(['price' => 50_000])];
        $bundle = Bundle::query()->create(['name' => 'Coffee set', 'slug' => 'coffee-set', 'price' => 120_000]);
        $bundle->items()->create(['product_id' => $a->id, 'quantity' => 1]);
        $bundle->items()->create(['product_id' => $b->id, 'quantity' => 1]);
        $calc = app(PriceCalculator::class);

        $guest = $calc->quote($this->items($a, $b), false);
        $this->assertSame(30_000, $guest->bundleDiscount);
        $this->assertSame(120_000, $guest->merchandiseTotal());
        $member = $calc->quote($this->items($a, $b), true);
        $this->assertSame(102_000, $member->merchandiseTotal()); // member price of the set

        $this->assertSame(0, $calc->quote($this->items($a), false)->bundleDiscount); // one piece missing

        Coupon::query()->create(['code' => 'TEN', 'type' => 'percent', 'value' => 1000]);
        $withCoupon = $calc->quote($this->items($a, $b), false, 'TEN');
        $this->assertSame(12_000, $withCoupon->couponDiscount); // 10% of the set price
        $this->assertSame(108_000, $withCoupon->merchandiseTotal());

        // Two sets need two of each.
        $two = $calc->quote([[$a->fresh()->load('variants'), null, 2], [$b->fresh()->load('variants'), null, 1]], false);
        $this->assertSame(30_000, $two->bundleDiscount);

        Features::set('shop.bundles', false);
        app(Bundles::class)->forget();
        $this->assertSame(0, $calc->quote($this->items($a, $b), false)->bundleDiscount);
    }

    public function test_add_the_set_to_cart_and_order_keeps_the_saving(): void
    {
        [$a, $b] = [Product::factory()->create(['price' => 100_000]), Product::factory()->create(['price' => 50_000])];
        $bundle = Bundle::query()->create(['name' => 'Coffee set', 'slug' => 'coffee-set', 'price' => 120_000]);
        $bundle->items()->create(['product_id' => $a->id, 'quantity' => 1]);
        $bundle->items()->create(['product_id' => $b->id, 'quantity' => 2]);

        $this->get('/shop/'.$a->slug)->assertSee('Save with a set')->assertSee('Coffee set');
        $res = $this->postJson("/api/v1/cart/bundles/{$bundle->id}")->assertCreated();
        $this->assertSame(80_000, $res->json('quote.bundle_discount'));
        $token = $res->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'A', 'phone' => '0911000304', 'city' => 'Adama', 'address' => 'x'])->assertCreated();
        $order = Order::query()->firstOrFail();
        $this->assertSame(80_000, $order->bundle_discount);
        $this->assertSame(120_000, $order->merchandise_total); // the base for team earnings too

        $bundle->update(['is_active' => false]);
        $this->postJson("/api/v1/cart/bundles/{$bundle->id}")->assertUnprocessable();
    }

    public function test_admin_flash_sale_rules_and_profit_warning(): void
    {
        $this->actingAsAdmin();
        $p = Product::factory()->create(['price' => 200_000]); // cost 750
        $base = ['product_id' => $p->id, 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String()];

        $this->postJson('/api/v1/admin/flash-sales', $base + ['price' => 200_000])->assertJsonValidationErrors('price');
        // 900 → member 765 − 76.5 − 26.8 − 750 < 0
        $this->postJson('/api/v1/admin/flash-sales', $base + ['price' => 90_000])->assertJsonValidationErrors('profit');
        $this->getJson("/api/v1/admin/flash-sales/check?product_id={$p->id}&price=90000")->assertOk()
            ->assertJsonPath('data.profit', fn ($v) => $v < 0)->assertJsonPath('data.line', fn ($l) => str_contains($l, 'loses'));
        $id = $this->postJson('/api/v1/admin/flash-sales', $base + ['price' => 150_000, 'stock_limit' => 3])->assertCreated()
            ->assertJsonPath('data.status', 'live')->json('data.id');
        $this->postJson('/api/v1/admin/flash-sales', $base + ['price' => 140_000])->assertJsonValidationErrors('starts_at'); // overlap
        $this->postJson('/api/v1/admin/flash-sales', $base + ['price' => 90_000, 'accept_loss' => true, 'is_active' => false])->assertCreated();
        $this->getJson('/api/v1/admin/flash-sales?status=live')->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/admin/flash-sales/$id")->assertOk();
        $this->assertDatabaseMissing('flash_sales', ['id' => $id]);

        $this->actingAsAdmin('support');
        $this->getJson('/api/v1/admin/flash-sales')->assertForbidden();
    }

    public function test_admin_bundle_rules_and_profit_warning(): void
    {
        $this->actingAsAdmin();
        [$a, $b] = [Product::factory()->create(['price' => 200_000]), Product::factory()->create(['price' => 200_000])];
        $v = ProductVariant::query()->forceCreate(['product_id' => $b->id, 'name' => 'Blue', 'stock' => 5, 'is_active' => true]);
        $items = [['product_id' => $a->id, 'quantity' => 1], ['product_id' => $b->id, 'quantity' => 1]];

        $this->postJson('/api/v1/admin/bundles', ['name' => 'Set', 'price' => 300_000, 'items' => $items])->assertJsonValidationErrors('items.1.product_variant_id');
        $items[1]['product_variant_id'] = $v->id;
        $this->postJson('/api/v1/admin/bundles', ['name' => 'Set', 'price' => 400_000, 'items' => $items])->assertJsonValidationErrors('price');
        $this->postJson('/api/v1/admin/bundles', ['name' => 'Set', 'price' => 150_000, 'items' => $items])->assertJsonValidationErrors('profit');
        $this->postJson('/api/v1/admin/bundles/check', ['price' => 150_000, 'items' => $items])->assertOk()->assertJsonPath('data.profit', fn ($p) => $p < 0);
        $this->postJson('/api/v1/admin/bundles', ['name' => 'Gift set', 'price' => 340_000, 'items' => $items])->assertCreated()
            ->assertJsonPath('data.slug', 'gift-set')->assertJsonPath('data.regular_price', 400_000)->assertJsonCount(2, 'data.items');
        $this->assertSame(2, Bundle::query()->firstOrFail()->items()->count());
    }

    public function test_bought_together_and_one_tap_suggestions(): void
    {
        [$a, $b, $c] = [Product::factory()->create(['name' => 'Jebena']), Product::factory()->create(['name' => 'Sini cups']), Product::factory()->create(['name' => 'Basket'])];
        $order = Order::query()->create(['number' => 'KM55501', 'first_name' => 'A', 'phone' => '+251911000001', 'city' => 'A', 'address' => 'B',
            'list_subtotal' => 1, 'merchandise_total' => 1, 'total' => 1, 'checkout_key' => 'k1', 'status' => Order::PAID, 'paid_at' => now()]);
        foreach ([$a, $b] as $p) {
            $order->items()->create(['product_id' => $p->id, 'name' => $p->name, 'quantity' => 1, 'list_unit_price' => 1, 'unit_price' => 1, 'unit_cost' => 0, 'line_total' => 1]);
        }
        $this->get('/shop/'.$a->slug)->assertSee('Frequently bought together')->assertSee('Sini cups');

        $res = $this->postJson('/api/v1/cart/items', ['product_id' => $a->id])->assertCreated();
        $this->assertSame($b->id, $res->json('suggestions.0.product_id'));
        $this->assertTrue($res->json('suggestions.0.one_tap'));
        $this->assertNotContains($a->id, array_column($res->json('suggestions'), 'product_id'));

        Features::set('shop.upsells', false);
        $this->assertSame([], $this->getJson('/api/v1/cart')->json('suggestions'));
        unset($c);
    }
}
