<?php

namespace Tests\Feature\Storefront;

use App\Home\HomePublisher;
use App\Home\HomeTemplates;
use App\Home\SafeLink;
use App\Home\Sections;
use App\Models\ActivityLog;
use App\Models\Banner;
use App\Models\Category;
use App\Models\HomePageVersion;
use App\Models\Product;
use App\Models\User;
use App\Storefront\PageCache;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PageCache::clear();
        parent::tearDown();
    }

    private function shop(): void
    {
        $cat = Category::factory()->create(['name' => 'Baskets', 'slug' => 'baskets']);
        Category::factory()->create(['name' => 'Scarves', 'slug' => 'scarves']);
        $hero = Product::factory()->create(['name' => 'Mesob basket', 'is_featured' => true, 'category_id' => $cat->id]);
        $hero->images()->create(['path' => "products/{$hero->id}/abc", 'formats' => ['avif', 'webp', 'jpg'], 'widths' => [400, 800], 'width' => 800, 'height' => 1000, 'alt' => 'A round basket']);
        Product::factory()->count(3)->create();
        Product::factory()->create(['name' => 'Gold cross', 'is_members_only' => true]);
        Banner::query()->create(['placement' => 'home', 'title' => 'Timket sale', 'text' => 'Ten days only', 'link_url' => '/shop', 'link_text' => 'Shop it', 'is_active' => true]);
    }

    private function main(string $html): string
    {
        $this->assertSame(1, preg_match('#<main id="main"[^>]*>(.*)</main>#s', $html, $m));
        $s = preg_replace('/\s+/', ' ', $m[1]) ?? '';
        $s = preg_replace('/\s*(<|>)\s*/', '$1', $s) ?? '';

        return preg_replace('/\s+(?=>)/', '', $s) ?? '';
    }

    private function publishSections(array $sections, ?User $by = null): HomePageVersion
    {
        $by ??= User::factory()->role('super_admin')->create();
        $p = app(HomePublisher::class);
        $p->saveDraft($sections, [], HomePageVersion::draft()->revision ?? 0, $by);

        return $p->publish($by);
    }

    public function test_the_current_template_looks_exactly_like_the_fixed_home_page(): void
    {
        $this->shop();
        foreach ([[], ['membership' => false], ['shop' => false], ['shop.members_only_product' => false, 'shop.early_access' => false]] as $off) {
            foreach ($off as $k => $v) {
                Features::set($k, $v);
            }
            HomePageVersion::query()->delete();
            HomePageVersion::forgetMemo();
            $fixed = $this->main($this->get('/')->assertOk()->getContent());
            $this->publishSections(HomeTemplates::sections('current'));
            $built = $this->main($this->get('/')->assertOk()->getContent());
            if ($fixed !== $built) {
                $i = strspn($fixed ^ $built, "\0");
                $this->fail('switched off: '.json_encode($off)."\nfixed: ".substr($fixed, max(0, $i - 150), 400)."\nbuilt: ".substr($built, max(0, $i - 150), 400));
            }
            foreach ($off as $k => $v) {
                Features::set($k, true);
            }
        }
        $this->assertStringContainsString('Mesob basket', $built);
    }

    public function test_switch_off_or_nothing_published_shows_the_fixed_page(): void
    {
        $this->shop();
        $this->get('/')->assertOk()->assertSee('Beautiful things, made slowly by hand.');
        $hero = Sections::make('hero');
        $hero['data']['headline'] = 'Our own words';
        $this->publishSections([$hero]);
        $this->get('/')->assertSee('Our own words')->assertDontSee('Beautiful things, made slowly');

        Features::set('site.home_builder', false);
        $this->get('/')->assertDontSee('Our own words')->assertSee('Beautiful things, made slowly by hand.');
    }

    public function test_a_draft_is_not_public_until_published_and_publishing_clears_the_page_cache(): void
    {
        config(['shop.page_cache_in_tests' => true]);
        $this->shop();
        $admin = $this->actingAsAdmin();
        $state = $this->getJson('/api/v1/admin/home-page')->assertOk()->assertJsonPath('draft.saved', false)->json('draft');
        $this->assertSame(array_column(HomeTemplates::sections('current'), 'type'), array_column($state['sections'], 'type'), 'starts from the current page');

        $sections = $state['sections'];
        $sections[0]['data']['headline'] = 'Draft headline';
        $rev = $this->putJson('/api/v1/admin/home-page/draft', ['sections' => $sections, 'seo' => [], 'revision' => 0])->assertOk()->json('revision');
        $this->assertSame(1, $rev);

        auth()->guard('web')->logout();
        $this->get('/')->assertOk()->assertDontSee('Draft headline')->assertHeader('X-Page-Cache', 'stored');
        $this->assertFileExists(public_path('page-cache/index.html'));

        $this->actingAs($admin);
        $this->postJson('/api/v1/admin/home-page/publish', ['note' => 'New words'])->assertOk()->assertJsonPath('draft.saved', false);
        $this->assertFileDoesNotExist(public_path('page-cache/index.html'), 'publishing clears the cached pages');
        auth()->guard('web')->logout();
        $this->get('/')->assertSee('Draft headline');

        $log = ActivityLog::query()->where('action', 'home.published')->sole();
        $this->assertSame($admin->id, $log->actor_id);
    }

    public function test_reorder_switch_off_duplicate_and_delete_sections(): void
    {
        $this->shop();
        $cta = Sections::make('cta');
        $cta['data']['title'] = 'Band one';
        $copy = [...$cta, 'id' => Sections::newId()];
        $copy['data']['title'] = 'Band two';
        $hidden = Sections::make('how_it_works');
        $hidden['on'] = false;
        $hero = Sections::make('hero');

        $this->publishSections([$hero, $copy, $cta, $hidden]);
        $html = $this->get('/')->getContent();
        $this->assertLessThan(strpos($html, 'Band one'), strpos($html, 'Band two'), 'order is kept');
        $this->assertStringNotContainsString('Pick something you love', $html, 'switched-off section is not shown');

        $this->publishSections([$hero, $cta]);
        $this->get('/')->assertSee('Band one')->assertDontSee('Band two');
    }

    public function test_two_windows_cannot_overwrite_each_other(): void
    {
        $this->actingAsAdmin();
        $sections = [Sections::make('hero')];
        $this->putJson('/api/v1/admin/home-page/draft', ['sections' => $sections, 'revision' => 0])->assertOk();
        $this->putJson('/api/v1/admin/home-page/draft', ['sections' => $sections, 'revision' => 0])->assertStatus(409);
        $this->putJson('/api/v1/admin/home-page/draft', ['sections' => $sections, 'revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->deleteJson('/api/v1/admin/home-page/draft')->assertOk();
        $this->assertNull(HomePageVersion::draft());
    }

    public function test_history_preview_and_restore(): void
    {
        $this->shop();
        $admin = $this->actingAsAdmin();
        $a = Sections::make('hero');
        $a['data']['headline'] = 'Version A';
        $first = $this->publishSections([$a], $admin);
        $b = Sections::make('hero');
        $b['data']['headline'] = 'Version B';
        $this->publishSections([$b], $admin);

        $list = $this->getJson('/api/v1/admin/home-page/versions')->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertTrue($list[0]['live']);
        $this->assertSame($admin->name, $list[0]['by']);
        $this->assertStringContainsString('Version A', $this->getJson("/api/v1/admin/home-page/versions/{$first->id}/preview")->assertOk()->json('html'));

        $this->postJson("/api/v1/admin/home-page/versions/{$first->id}/restore")->assertOk()->assertJsonPath('live.restored_from', $first->id);
        auth()->guard('web')->logout();
        $this->get('/')->assertSee('Version A')->assertDontSee('Version B');
        $this->assertSame(3, HomePageVersion::query()->where('status', 'published')->count(), 'history keeps every publish');
    }

    public function test_scheduled_publish(): void
    {
        $this->shop();
        $admin = $this->actingAsAdmin();
        $hero = Sections::make('hero');
        $hero['data']['headline'] = 'Planned words';
        $this->putJson('/api/v1/admin/home-page/draft', ['sections' => [$hero], 'revision' => 0])->assertOk();
        $this->postJson('/api/v1/admin/home-page/schedule', ['publish_at' => now()->subHour()->toIso8601String()])->assertUnprocessable();
        $at = now()->addHours(2);
        $this->postJson('/api/v1/admin/home-page/schedule', ['publish_at' => $at->toIso8601String()])->assertOk()->assertJsonPath('draft.saved', false);
        $this->getJson('/api/v1/admin/home-page')->assertJsonPath('scheduled.publish_at', $at->copy()->startOfSecond()->toIso8601String());

        $this->assertNull(app(HomePublisher::class)->publishDue(), 'not yet');
        auth()->guard('web')->logout();
        $this->get('/')->assertDontSee('Planned words');

        $this->travel(3)->hours();
        $v = app(HomePublisher::class)->publishDue();
        $this->assertNotNull($v);
        $this->assertSame($admin->id, $v->published_by);
        $this->get('/')->assertSee('Planned words');
        $this->assertNull(HomePageVersion::scheduled());

        // Cancel: the plan becomes the draft again.
        $this->travelBack();
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/home-page/schedule', ['publish_at' => now()->addDay()->toIso8601String()])->assertOk();
        $this->deleteJson('/api/v1/admin/home-page/schedule')->assertOk()->assertJsonPath('draft.saved', true);
        $this->assertNull(HomePageVersion::scheduled());
    }

    public function test_sections_of_switched_off_features_are_hidden_and_noted(): void
    {
        $this->shop();
        $signals = Sections::make('signals');
        $earn = Sections::make('how_you_earn');
        $this->publishSections([Sections::make('hero'), $signals, $earn, Sections::make('flash_sale')]);
        $this->get('/')->assertSee('Trading signals')->assertSee('Example: 3 people join with your link')
            ->assertDontSee('data-flash-sale', false); // no live sale: hidden

        Features::set('signals', false);
        Features::set('referral', false);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Trading signals', $html);
        $this->assertStringNotContainsString('Example: 3 people', $html);

        $this->actingAsAdmin();
        $res = $this->getJson('/api/v1/admin/home-page')->assertOk();
        $types = collect($res->json('types'))->keyBy('key');
        $this->assertStringContainsString('"Trading signals" is off', (string) $types['signals']['off']);
        $this->assertNull($types['hero']['off']);
        $this->assertContains('Signals is hidden now: "Trading signals" is off.', array_column($res->json('problems.warnings'), 'message'));
    }

    public function test_texts_use_live_numbers_and_drop_words_of_off_features(): void
    {
        $this->shop();
        $hero = Sections::make('hero');
        $hero['data']['subline'] = 'Members save {member_discount}. Membership is {membership_price} a month.';
        $this->publishSections([$hero, Sections::make('membership')]);
        Settings::set('membership.price', 30_000);
        Settings::set('pricing.member_discount_bps', 1200);
        $this->get('/')->assertSee('Members save 12%. Membership is 300 ETB a month.')->assertSee('See membership — 300 ETB/month')->assertSee('12%');

        Features::set('membership', false);
        $html = $this->get('/')->getContent();
        $this->assertStringNotContainsString('Membership is', $html, 'a text about a switched-off feature is left out');
        $this->assertStringNotContainsString('Become a member', $html, 'its button too');
        $this->assertStringContainsString('Beautiful things, made slowly by hand.', $html, 'the rest of the hero stays');
    }

    public function test_trust_numbers_are_counted_and_zero_is_not_shown(): void
    {
        $this->shop();
        $n = Sections::make('numbers');
        $n['data']['stats'] = ['products', 'customers', 'members'];
        $this->publishSections([Sections::make('hero'), $n]);
        $html = $this->get('/')->getContent();
        $this->assertMatchesRegularExpression('#>5</dd>#', $this->main($html), '5 active products');
        $this->assertStringContainsString('Handmade pieces in the shop', $html);
        $this->assertStringNotContainsString('Happy customers', $html, 'no delivered orders yet: not shown');
        $this->assertStringNotContainsString('>Members<', $html);
    }

    public function test_texts_are_escaped_and_bad_links_never_reach_the_page(): void
    {
        $this->shop();
        $admin = $this->actingAsAdmin();
        $hero = Sections::make('hero');
        $hero['data']['headline'] = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $hero['data']['button1_link'] = 'javascript:alert(3)';
        $this->putJson('/api/v1/admin/home-page/draft', ['sections' => [$hero], 'revision' => 0])->assertOk()
            ->assertJsonFragment(['message' => 'Hero → Button 1 link is not an allowed link. '.SafeLink::HELP.'.']);
        $this->postJson('/api/v1/admin/home-page/publish')->assertUnprocessable();
        $this->assertNull(HomePageVersion::live());

        // Even a bad link that got into the database is not shown.
        HomePageVersion::query()->create(['status' => 'published', 'published_at' => now(), 'sections' => [$hero], 'published_by' => $admin->id]);
        auth()->guard('web')->logout();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('Shop the collection', $html, 'the button with a bad link is left out');
    }

    public function test_safe_links(): void
    {
        foreach (['/shop', '/c/baskets?x=1', '#faq', 'https://t.me/kulmis', 'http://example.com/a', 'mailto:hello@kulmis.market', 'tel:+251900000000'] as $ok) {
            $this->assertSame($ok, SafeLink::clean($ok), $ok);
        }
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', ' javascript:x', 'data:text/html,x', '//evil.com', '/\\evil.com', 'https://shop.et@evil.com',
            "/shop\nx", 'vbscript:x', 'https://', 'mailto:nope', 'tel:abc', 'shop', '&#106;avascript:x', '/a"onmouseover="x'] as $bad) {
            $this->assertNull(SafeLink::clean($bad), $bad);
        }
    }

    public function test_publish_checks_and_only_super_admin_or_content_editor_can_edit(): void
    {
        $this->actingAsAdmin();
        $this->putJson('/api/v1/admin/home-page/draft', ['sections' => [], 'revision' => 0])->assertOk()
            ->assertJsonPath('problems.errors.0.message', 'The page is empty. Switch on or add at least one section.');
        $this->postJson('/api/v1/admin/home-page/publish')->assertUnprocessable();
        $hero = Sections::make('hero');
        $hero['data']['headline'] = '';
        $res = $this->putJson('/api/v1/admin/home-page/draft', ['sections' => [Sections::make('cta'), $hero], 'revision' => 1])->assertOk();
        $this->assertContains('Hero → Headline is empty.', array_column($res->json('problems.errors'), 'message'));
        $this->assertStringContainsString('Put the Hero first', implode(' ', array_column($res->json('problems.warnings'), 'message')));

        $this->actingAsAdmin('content_editor');
        $this->getJson('/api/v1/admin/home-page')->assertOk();
        $this->getJson('/api/v1/admin/pages')->assertOk();
        $this->getJson('/api/v1/admin/orders')->assertForbidden();
        foreach (['manager', 'finance', 'support', 'fulfilment'] as $role) {
            $this->actingAsAdmin($role);
            $this->getJson('/api/v1/admin/home-page')->assertForbidden();
            $this->postJson('/api/v1/admin/home-page/publish')->assertForbidden();
        }
    }

    public function test_preview_is_the_page_as_a_visitor_sees_it(): void
    {
        $this->shop();
        $this->actingAsAdmin();
        $hero = Sections::make('hero');
        $hero['data']['headline'] = 'Typed just now';
        $html = $this->postJson('/api/v1/admin/home-page/preview', ['sections' => [$hero, Sections::make('cta')], 'dark' => true])->assertOk()->json('html');
        $this->assertStringContainsString('Typed just now', $html);
        $this->assertStringContainsString('data-home-section="'.$hero['id'].'"', $html);
        $this->assertStringContainsString('data-home-preview', $html);
        $this->assertMatchesRegularExpression('/<html[^>]*class="h-full dark"/', $html);
        $this->assertNull(HomePageVersion::draft(), 'a preview saves nothing');
        $this->assertStringNotContainsString('data-home-section', $this->get('/')->getContent());
    }

    public function test_seo_title_description_and_unknown_input_is_dropped(): void
    {
        $this->shop();
        $raw = [['type' => 'nope', 'data' => []], ['type' => 'hero', 'id' => 'x', 'on' => true, 'data' => ['headline' => str_repeat('a', 500), 'layout' => 'sideways', 'extra' => 'x', 'accent' => 'url(evil)']]];
        $clean = Sections::normalize($raw);
        $this->assertCount(1, $clean);
        $this->assertSame(120, mb_strlen($clean[0]['data']['headline']));
        $this->assertSame('right', $clean[0]['data']['layout']);
        $this->assertSame('soft', $clean[0]['data']['accent']);
        $this->assertArrayNotHasKey('extra', $clean[0]['data']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{10}$/', $clean[0]['id']);

        $by = User::factory()->role('super_admin')->create();
        app(HomePublisher::class)->saveDraft([Sections::make('hero')], ['title' => 'Kulmis — handmade baskets', 'description' => 'Baskets woven in Gondar.'], 0, $by);
        app(HomePublisher::class)->publish($by);
        $this->get('/')->assertSee('<title>Kulmis — handmade baskets</title>', false)->assertSee('<meta name="description" content="Baskets woven in Gondar.">', false);
    }
}
