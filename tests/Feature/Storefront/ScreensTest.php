<?php

namespace Tests\Feature\Storefront;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fixes from the screen review. */
class ScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_pages_are_branded_and_helpful(): void
    {
        $r = $this->get('/no-such-page')->assertNotFound();
        $r->assertSee("We can't find that page", false)->assertSee('Go to the shop')->assertSee(config('app.name'));
        $r->assertDontSee('@section', false);
        foreach ([403, 419, 429, 500, 503] as $code) {
            $html = view("errors.$code")->render();
            $this->assertStringContainsString('<h1', $html);
            $this->assertStringNotContainsString('@section', $html);
            $this->assertStringNotContainsString('javascript:', $html, 'blocked by the CSP');
        }
    }

    public function test_made_to_order_badge_is_readable_in_dark_mode(): void
    {
        Product::factory()->create(['stock_mode' => 'made_to_order', 'making_days' => 5]);
        $html = $this->get('/shop')->getContent();
        $this->assertStringContainsString('dark:text-mist-100">Made to order', $html);
        $this->assertStringContainsString('dark:bg-ink-900/90', $html);
    }

    public function test_shop_has_a_proper_heading_order(): void
    {
        Product::factory()->create();
        $this->get('/shop')->assertSee('<h2 class="sr-only">Products</h2>', false);
    }

    public function test_text_is_compressed_by_the_web_server(): void
    {
        $this->assertStringContainsString('gzip on;', (string) file_get_contents(base_path('docker/nginx/default.conf')));
        $this->assertStringContainsString('mod_deflate', (string) file_get_contents(public_path('.htaccess')));
    }
}
