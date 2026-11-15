<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\Csp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Referral\ReferralTestCase;

/**
 * Speed guards: query budgets per page (stop N+1 creeping back), instant
 * navigation, the main photo preloaded, and a careful service worker.
 */
class PerformanceTest extends ReferralTestCase
{
    private function queries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    private function catalog(int $n = 24): void
    {
        $cat = Category::query()->create(['name' => 'Baskets', 'slug' => 'baskets', 'is_active' => true]);
        Product::factory()->count($n)->create(['category_id' => $cat->id, 'is_featured' => true]);
    }

    public function test_public_pages_stay_within_their_query_budget(): void
    {
        $this->catalog();
        $slug = Product::query()->value('slug');
        $this->get('/'); // warm the settings/feature caches like a real server
        foreach (['/' => 16, '/shop' => 12, "/shop/$slug" => 14, '/membership' => 6, '/signals' => 8] as $path => $budget) {
            $n = $this->queries(fn () => $this->get($path)->assertOk());
            $this->assertLessThanOrEqual($budget, $n, "$path used $n queries (budget $budget): look for an N+1");
        }
        // 24 products or 4: the shop page costs the same (eager loading).
        $many = $this->queries(fn () => $this->get('/shop')->assertOk());
        Product::query()->limit(20)->delete();
        $few = $this->queries(fn () => $this->get('/shop')->assertOk());
        $this->assertSame($few, $many, 'query count must not grow with the number of products');
    }

    public function test_member_pages_stay_within_their_query_budget(): void
    {
        [$top] = $this->chain();
        foreach (range(1, 15) as $i) {
            $this->member($top, "Team$i");
        }
        $this->actingAs($top->fresh(), 'web');
        $this->get('/account');
        foreach (['/account' => 70, '/account/team' => 16, '/account/wallet' => 8, '/account/earnings' => 14] as $path => $budget) {
            $n = $this->queries(fn () => $this->get($path)->assertOk());
            $this->assertLessThanOrEqual($budget, $n, "$path used $n queries (budget $budget)");
        }
    }

    public function test_admin_dashboard_is_cached_until_money_moves(): void
    {
        $this->actingAsAdmin();
        $first = $this->queries(fn () => $this->getJson('/api/v1/admin/dashboard')->assertOk());
        $second = $this->queries(fn () => $this->getJson('/api/v1/admin/dashboard')->assertOk());
        $this->assertLessThan(15, $second, "second load used $second queries (first $first)");

        $before = $this->getJson('/api/v1/admin/dashboard')->json('data.revenue.memberships');
        $this->payMembership(User::factory()->create()); // a paid membership posts to the ledger
        $after = $this->getJson('/api/v1/admin/dashboard')->json('data.revenue.memberships');
        $this->assertGreaterThan($before, $after, 'a ledger entry must show up right away');
    }

    public function test_instant_navigation_never_prefetches_private_pages(): void
    {
        $html = (string) $this->get('/')->getContent();
        $this->assertStringContainsString('<script type="speculationrules">'.Csp::SPECULATION_RULES.'</script>', $html);
        $rules = json_decode(Csp::SPECULATION_RULES, true);
        $not = $rules['prefetch'][0]['where']['and'][1]['not']['href_matches'];
        foreach (['/account*', '/admin*', '/logout', '/cart', '/checkout', '/pay/*', '/api/*'] as $private) {
            $this->assertContains($private, $not);
        }
        $this->assertStringContainsString(substr(Csp::storefront(), 0, 10), (string) $this->get('/')->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString(base64_encode(hash('sha256', Csp::SPECULATION_RULES, true)), Csp::storefront());
    }

    public function test_main_photo_is_preloaded_on_product_and_home_pages(): void
    {
        $p = Product::factory()->create(['is_featured' => true]);
        $p->images()->create(['path' => "products/{$p->id}/abc", 'formats' => ['avif', 'webp', 'jpg'], 'widths' => [400, 800],
            'width' => 1000, 'height' => 800, 'alt' => 'x', 'position' => 1]);
        foreach (['/', "/shop/{$p->slug}"] as $path) {
            $html = (string) $this->get($path)->getContent();
            $this->assertMatchesRegularExpression('#<link rel="preload" as="image" type="image/avif" imagesrcset="[^"]+abc-400\.avif 400w[^"]*" imagesizes="[^"]+" fetchpriority="high">#', $html, $path);
            $this->assertLessThan(strpos($html, 'rel="stylesheet"'), strpos($html, 'rel="preload" as="image"'), 'preload before the CSS');
        }
    }

    public function test_service_worker_never_keeps_private_pages(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString('const PRIVATE = /^\\/(account|admin|api|cart|checkout|pay|login|register|logout|forgot|r)(\\/|$)/;', $sw);
        $this->assertStringContainsString("cc.includes('public') && !cc.includes('no-store')", $sw, 'only public responses are saved');
        $this->assertFileExists(public_path('offline.html'));
        $this->assertStringContainsString('location = /sw.js', (string) file_get_contents(base_path('docker/nginx/default.conf')));
        $this->assertStringContainsString('<Files "sw.js">', (string) file_get_contents(public_path('.htaccess')));
        // Signed-in pages are private, so the worker would skip them even outside those paths.
        $this->actingAs($this->member(null, 'Sw'), 'web');
        $this->assertStringNotContainsString('public', (string) $this->get('/account')->headers->get('Cache-Control'));
    }

    public function test_deploys_empty_the_page_cache(): void
    {
        File::ensureDirectoryExists(public_path('page-cache'));
        file_put_contents(public_path('page-cache/index.html'), 'old page with old CSS name');
        $this->artisan('optimize')->assertSuccessful();
        $this->assertFileDoesNotExist(public_path('page-cache/index.html'), 'php artisan optimize must clear cached pages');
        $this->artisan('optimize:clear')->assertSuccessful();
    }
}
