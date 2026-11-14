<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use App\Models\User;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_api_never_exposes_cost(): void
    {
        Product::factory()->create(['slug' => 'basket']);

        $json = $this->getJson('/api/v1/products/basket')->assertOk()->json('data');
        foreach (['cost_materials', 'cost_labour', 'cost_packaging', 'total_cost'] as $k) {
            $this->assertArrayNotHasKey($k, $json);
        }
        $this->assertSame(200_000, $json['price']);
    }

    public function test_drafts_and_archived_are_hidden(): void
    {
        Product::factory()->draft()->create(['slug' => 'draft-one']);
        Product::factory()->create(['slug' => 'old', 'status' => 'archived']);

        $this->getJson('/api/v1/products/draft-one')->assertNotFound();
        $this->getJson('/api/v1/products/old')->assertNotFound();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_members_get_48_hour_early_access(): void
    {
        $this->freezeTime();
        Product::factory()->create(['slug' => 'new-scarf', 'release_at' => now()->addHours(30)]);
        Product::factory()->create(['slug' => 'later', 'release_at' => now()->addHours(60)]);
        $member = User::factory()->member()->create();

        $this->getJson('/api/v1/products/new-scarf')->assertNotFound();               // guest: not yet
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/products/new-scarf')
            ->assertOk()->assertJsonPath('data.early_access', true)->assertJsonPath('data.purchasable', true);
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/products/later')->assertNotFound(); // > 48 h away

        $this->travel(31)->hours();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/products/new-scarf')->assertOk()->assertJsonPath('data.early_access', false);
    }

    public function test_early_access_switch_off_hides_from_members_too(): void
    {
        Product::factory()->create(['slug' => 'soon', 'release_at' => now()->addHours(10)]);
        Features::set('shop.early_access', false);

        $this->actingAs(User::factory()->member()->create(), 'sanctum')->getJson('/api/v1/products/soon')->assertNotFound();
    }

    public function test_expired_member_has_no_early_access(): void
    {
        Product::factory()->create(['slug' => 'soon', 'release_at' => now()->addHours(10)]);
        $user = User::factory()->member()->create();
        $user->membership->update(['current_period_end' => now()->subDays(5), 'grace_ends_at' => now()->subDays(2)]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/products/soon')->assertNotFound();
    }

    public function test_members_only_product_is_visible_but_only_members_can_buy(): void
    {
        $p = Product::factory()->create(['slug' => 'motm', 'is_members_only' => true]);

        $this->getJson('/api/v1/products/motm')->assertOk()->assertJsonPath('data.purchasable', false);
        $this->actingAs(User::factory()->member()->create(), 'sanctum')
            ->getJson('/api/v1/products/motm')->assertJsonPath('data.purchasable', true);

        Features::set('shop.members_only_product', false);
        $this->assertTrue($p->fresh()->isPurchasableBy(false));
    }

    public function test_price_warning_below_2_5x_cost(): void
    {
        $ok = Product::factory()->create(['price' => 187_500]);  // exactly 2.5 × 750
        $low = Product::factory()->create(['price' => 187_499]);

        $this->assertSame([], $ok->priceWarnings());
        $this->assertSame('price_below_cost_ratio', $low->priceWarnings()[0]['code']);

        Settings::set('catalog.min_price_cost_ratio', 200);
        $this->assertSame([], $low->fresh()->priceWarnings());
    }

    public function test_variant_price_also_checked(): void
    {
        $p = Product::factory()->create(['price' => 300_000]);
        $p->variants()->create(['name' => 'Small', 'price' => 100_000, 'stock' => 2]);

        $this->assertNotEmpty($p->fresh()->priceWarnings());
    }

    public function test_stock_sums_active_variants_and_made_to_order_is_unlimited(): void
    {
        $p = Product::factory()->create(['stock' => 99]);
        $p->variants()->create(['name' => 'S', 'stock' => 2]);
        $p->variants()->create(['name' => 'M', 'stock' => 3]);
        $p->variants()->create(['name' => 'L', 'stock' => 50, 'is_active' => false]);

        $this->assertSame(5, $p->fresh()->availableStock());
        $this->assertNull(Product::factory()->madeToOrder()->create()->availableStock());
    }

    public function test_shop_switch_off_hides_catalog_api(): void
    {
        Product::factory()->create();
        Features::set('shop', false);

        $this->getJson('/api/v1/products')->assertNotFound();
    }

    public function test_filters_and_sorting(): void
    {
        Product::factory()->create(['name' => 'Leather bag', 'price' => 500_000, 'is_featured' => true]);
        Product::factory()->create(['name' => 'Clay cup', 'price' => 90_000]);

        $this->getJson('/api/v1/products?sort=price_asc')->assertJsonPath('data.0.name', 'Clay cup');
        $this->getJson('/api/v1/products?featured=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?q=leather')->assertJsonCount(1, 'data');
    }
}
