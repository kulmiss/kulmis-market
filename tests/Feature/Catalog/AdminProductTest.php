<?php

namespace Tests\Feature\Catalog;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Handwoven Gabi', 'price' => 350_000, 'cost_materials' => 80_000, 'cost_labour' => 50_000,
            'cost_packaging' => 10_000, 'weight_grams' => 1200, 'stock_mode' => 'stock', 'stock' => 4,
            'status' => 'active', 'tags' => ['cotton', 'gift'],
            'variants' => [
                ['name' => 'Single', 'options' => ['size' => 'single'], 'stock' => 3],
                ['name' => 'Double', 'options' => ['size' => 'double'], 'price' => 520_000, 'stock' => 1],
            ],
        ], $over);
    }

    public function test_create_product_with_variants_tags_and_cost(): void
    {
        $this->actingAsAdmin();
        $cat = Category::factory()->create();

        $res = $this->postJson('/api/v1/admin/products', $this->payload(['category_id' => $cat->id]))->assertCreated();
        $res->assertJsonPath('data.total_cost', 140_000)->assertJsonPath('data.available_stock', 4)
            ->assertJsonPath('data.warnings', [])->assertJsonCount(2, 'data.variants')->assertJsonCount(2, 'data.tags');
        $this->assertTrue(ActivityLog::query()->where('action', 'product.created')->exists());
    }

    public function test_create_returns_price_warning(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/products', $this->payload(['price' => 300_000, 'variants' => []]))
            ->assertCreated()->assertJsonPath('data.warnings.0.code', 'price_below_cost_ratio');
    }

    public function test_made_to_order_requires_making_days(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/products', $this->payload(['stock_mode' => 'made_to_order', 'variants' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('making_days');
        $this->postJson('/api/v1/admin/products', $this->payload(['stock_mode' => 'made_to_order', 'making_days' => 10, 'variants' => []]))
            ->assertCreated()->assertJsonPath('data.available_stock', null);
    }

    public function test_removed_variants_are_deactivated_not_deleted(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/v1/admin/products', $this->payload())->json('data.id');
        $variants = Product::query()->findOrFail($id)->variants;

        $this->putJson("/api/v1/admin/products/$id", $this->payload(['variants' => [
            ['id' => $variants[0]->id, 'name' => 'Single', 'stock' => 7],
        ]]))->assertOk();

        $this->assertSame(7, $variants[0]->fresh()->stock);
        $this->assertFalse($variants[1]->fresh()->is_active);
    }

    public function test_bulk_actions_and_filters(): void
    {
        $this->actingAsAdmin();
        $a = Product::factory()->create(['stock' => 1]);
        $b = Product::factory()->create(['stock' => 50]);

        $this->postJson('/api/v1/admin/products/bulk', ['ids' => [$a->id, $b->id], 'action' => 'feature'])->assertOk()->assertJson(['updated' => 2]);
        $this->assertTrue($b->fresh()->is_featured);

        $this->getJson('/api/v1/admin/products?low_stock=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $a->id);
        $this->postJson('/api/v1/admin/products/bulk', ['ids' => [$a->id], 'action' => 'archive'])->assertOk();
        $this->getJson('/api/v1/admin/products?status=archived')->assertJsonPath('total', 1);
    }

    public function test_image_upload_makes_responsive_formats(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $p = Product::factory()->create();

        $res = $this->postJson("/api/v1/admin/products/{$p->id}/images", [
            'image' => UploadedFile::fake()->image('photo.jpg', 2000, 1500),
        ])->assertCreated();

        $image = $p->images()->firstOrFail();
        $this->assertSame([400, 800, 1600], $image->widths);
        $this->assertContains('webp', $image->formats);
        Storage::disk('public')->assertExists($image->path.'-800.webp');
        Storage::disk('public')->assertExists($image->path.'-1600.jpg');
        $this->assertStringContainsString('400w', $res->json('data.srcsets.webp'));

        $this->deleteJson("/api/v1/admin/products/{$p->id}/images/{$image->id}")->assertOk();
        Storage::disk('public')->assertMissing($image->path.'-800.webp');
    }

    public function test_small_images_are_not_upscaled_and_bad_files_rejected(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $p = Product::factory()->create();

        $this->postJson("/api/v1/admin/products/{$p->id}/images", ['image' => UploadedFile::fake()->image('s.png', 500, 500)])->assertCreated();
        $this->assertSame([400, 500], $p->images()->firstOrFail()->widths);

        $this->postJson("/api/v1/admin/products/{$p->id}/images", ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertUnprocessable();
    }

    public function test_staff_without_ability_and_shop_off(): void
    {
        $this->actingAsAdmin('finance');
        $this->getJson('/api/v1/admin/products')->assertForbidden();

        $this->actingAsAdmin();
        Features::set('shop', false);
        $this->getJson('/api/v1/admin/products')->assertNotFound();
    }
}
