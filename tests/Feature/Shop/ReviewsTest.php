<?php

namespace Tests\Feature\Shop;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Reviews: verified buyers only (after delivery), photos re-encoded, nothing shows before approval. */
class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(Product $p, string $status = 'delivered'): User
    {
        $u = User::factory()->create(['first_name' => 'Abebe', 'last_name' => 'Kebede']);
        $o = Order::query()->create(['number' => 'KM'.random_int(10000, 99999), 'user_id' => $u->id, 'first_name' => 'A', 'phone' => '+251911000001',
            'city' => 'A', 'address' => 'B', 'list_subtotal' => 1, 'merchandise_total' => 1, 'total' => 1, 'checkout_key' => uniqid('k', true), 'status' => $status]);
        $o->items()->create(['product_id' => $p->id, 'name' => $p->name, 'quantity' => 1, 'list_unit_price' => 1, 'unit_price' => 1, 'line_total' => 1]);

        return $u;
    }

    private function admin(): User
    {
        $a = User::factory()->superAdmin()->create();
        $a->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        return $a;
    }

    public function test_only_delivered_buyers_can_review_once(): void
    {
        $p = Product::factory()->create();
        $form = ['rating' => 5, 'title' => 'Lovely', 'body' => 'Great quality and fast delivery.'];

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get('/shop/'.$p->slug)->assertDontSee('Write your review');
        $this->post('/account/reviews/'.$p->id, $form)->assertSessionHasErrors('review');

        $notYet = $this->buyer($p, 'shipped');
        $this->actingAs($notYet)->post('/account/reviews/'.$p->id, $form)->assertSessionHasErrors('review');

        $buyer = $this->buyer($p);
        $this->actingAs($buyer)->get('/shop/'.$p->slug)->assertSee('Write your review');
        $this->post('/account/reviews/'.$p->id, $form)->assertRedirect('/shop/'.$p->slug.'#reviews');
        $this->post('/account/reviews/'.$p->id, $form)->assertSessionHasErrors('review');
        $this->assertSame(1, ProductReview::query()->count());
        $this->post('/account/reviews/'.$p->id, ['rating' => 9, 'body' => 'x'])->assertSessionHasErrors(['rating', 'body']);
    }

    public function test_nothing_shows_before_approval_then_rating_is_computed(): void
    {
        $p = Product::factory()->create();
        $buyer = $this->buyer($p);
        $this->actingAs($buyer)->post('/account/reviews/'.$p->id, ['rating' => 4, 'body' => 'Strong and well made basket.']);
        auth()->logout();
        $this->get('/shop/'.$p->slug)->assertDontSee('Strong and well made basket.')->assertSee('No reviews yet');

        $review = ProductReview::query()->firstOrFail();
        $this->actingAs($this->admin())->getJson('/api/v1/admin/reviews')->assertOk()->assertJsonPath('data.0.author', 'Abebe K.');
        $this->postJson("/api/v1/admin/reviews/{$review->id}/approve")->assertOk();
        $this->assertSame(1, $p->fresh()->rating_count);
        $this->assertEquals(4.0, (float) $p->fresh()->rating_avg);
        auth()->logout();
        $page = $this->get('/shop/'.$p->slug);
        $page->assertSee('Strong and well made basket.')->assertSee('Abebe K.')->assertSee('Verified buyer')->assertDontSee('Kebede')
            ->assertSee('"aggregateRating"', false);

        $this->actingAs($this->admin())->postJson("/api/v1/admin/reviews/{$review->id}/reject", ['reason' => 'test'])->assertOk();
        $this->assertSame(0, $p->fresh()->rating_count);
    }

    public function test_review_text_is_escaped_and_photos_are_re_encoded(): void
    {
        Storage::fake('public');
        $p = Product::factory()->create();
        $buyer = $this->buyer($p);
        $this->actingAs($buyer)->post('/account/reviews/'.$p->id, [
            'rating' => 5, 'body' => '<script>alert(1)</script> ቆንጆ ስራ 👍 nice',
            'photos' => [UploadedFile::fake()->image('a.jpg', 1600, 1200), UploadedFile::fake()->create('evil.php', 10, 'application/x-php')],
        ])->assertSessionHasErrors('photos.1');
        $this->post('/account/reviews/'.$p->id, ['rating' => 5, 'body' => '<script>alert(1)</script> ቆንጆ ስራ 👍 nice',
            'photos' => [UploadedFile::fake()->image('a.jpg', 1600, 1200)]])->assertRedirect();
        $review = ProductReview::query()->firstOrFail();
        $this->assertCount(1, $review->photos);
        $this->assertMatchesRegularExpression('#^reviews/\d+/[a-z0-9]{24}\.(webp|jpg)$#', $review->photos[0]);
        [$w] = getimagesizefromstring(Storage::disk('public')->get($review->photos[0]));
        $this->assertSame(1200, $w, 'resized');

        $review->update(['status' => 'approved']);
        auth()->logout();
        $this->get('/shop/'.$p->slug)->assertDontSee('<script>alert(1)</script>', false)->assertSee('ቆንጆ ስራ 👍');
    }

    public function test_demo_reviews_hide_when_demo_data_is_off_and_staff_permissions(): void
    {
        $p = Product::factory()->create();
        $u = User::factory()->create(['is_demo' => true]);
        ProductReview::query()->create(['product_id' => $p->id, 'user_id' => $u->id, 'rating' => 5, 'body' => 'Demo words here', 'status' => 'approved', 'is_demo' => true]);
        Settings::set('demo.show', false);
        $this->get('/shop/'.$p->slug)->assertDontSee('Demo words here');

        $fulfilment = User::factory()->role('fulfilment')->create();
        $fulfilment->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($fulfilment)->getJson('/api/v1/admin/reviews')->assertForbidden();

        Features::set('shop.reviews', false);
        $this->actingAs($this->admin())->getJson('/api/v1/admin/reviews')->assertNotFound();
    }
}
