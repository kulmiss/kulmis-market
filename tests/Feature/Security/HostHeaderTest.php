<?php

namespace Tests\Feature\Security;

use App\Http\TrustedHosts;
use App\Models\Product;
use App\Storefront\PageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

/**
 * Host-header injection: a request with a forged Host must never put that host
 * into links, asset URLs or the static page cache (web cache poisoning).
 */
class HostHeaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.page_cache_in_tests' => true]);
        PageCache::clear();
    }

    protected function tearDown(): void
    {
        PageCache::clear();
        parent::tearDown();
    }

    public function test_forged_host_is_not_cached_and_not_used_in_urls(): void
    {
        Product::factory()->create(['slug' => 'clay-pot', 'name' => 'Clay pot']);

        $res = $this->get('http://evil.example/shop/clay-pot');
        $res->assertOk()->assertHeaderMissing('X-Page-Cache')->assertDontSee('evil.example', false);
        $this->assertFileDoesNotExist(public_path('page-cache/shop/clay-pot.html'));

        // The real site name is cached as before, with canonical URLs only.
        $this->get(rtrim((string) config('app.url'), '/').'/shop/clay-pot')->assertHeader('X-Page-Cache', 'stored');
        $this->assertStringContainsString((string) config('app.url'), File::get(public_path('page-cache/shop/clay-pot.html')));
    }

    public function test_canonical_host_includes_the_port(): void
    {
        config(['app.url' => 'http://localhost:8090']);
        $this->assertSame('localhost:8090', PageCache::canonicalHost());
        config(['app.url' => 'https://Kulmis.Market']);
        $this->assertSame('kulmis.market', PageCache::canonicalHost());
    }

    public function test_production_refuses_unknown_hosts(): void
    {
        config(['app.url' => 'https://kulmis.market', 'app.trusted_hosts' => 'www.kulmis.market']);
        $patterns = TrustedHosts::patterns();
        $this->assertSame(['^kulmis\.market$', '^www\.kulmis\.market$'], $patterns);

        Request::setTrustedHosts($patterns);
        try {
            $this->assertSame('www.kulmis.market', Request::create('https://www.kulmis.market/')->getHost());
            $this->expectException(SuspiciousOperationException::class);
            Request::create('https://kulmis.market.evil.example/')->getHost();
        } finally {
            Request::setTrustedHosts([]);
        }
    }
}
