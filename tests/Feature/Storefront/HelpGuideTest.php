<?php

namespace Tests\Feature\Storefront;

use App\Help\HelpArticles;
use App\Help\HelpTokens;
use App\Models\Faq;
use App\Models\User;
use App\Support\Features;
use App\Support\Settings;
use Database\Seeders\PagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The member Help guide on /help: articles with live numbers, search, feedback, editing. */
class HelpGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_lists_every_topic_and_twenty_questions(): void
    {
        $page = $this->get('/help')->assertOk();
        foreach (HelpArticles::TOPICS as $topic) {
            $page->assertSee($topic);
        }
        $page->assertSee('How earning works')->assertSee('Cash out')->assertSee('Questions and answers')->assertSee('Still need help?');
        $this->assertGreaterThanOrEqual(20, Faq::query()->where('is_published', true)->count());
        $this->get('/help?q=cash-out')->assertSee('data-help-article="cash-out"', false)->assertDontSee('data-help-article="badges"', false);
    }

    public function test_articles_show_live_numbers_from_the_settings(): void
    {
        $this->get('/help/how-earning-works')->assertOk()
            ->assertSee('On each 5,000 ETB membership')->assertSee('500 ETB')->assertSee('1,500 ETB every month', false)
            ->assertSee('Was this helpful?');
        $this->get('/help/cash-out')->assertSee('at least <strong>1,000 ETB</strong>', false)->assertSee('within <strong>3 days</strong>', false);

        Settings::set('wallet.cashout_min', 50_000);
        Settings::set('membership.price', 600_000);
        $this->get('/help/cash-out')->assertSee('at least <strong>500 ETB</strong>', false);
        $this->get('/help/how-earning-works')->assertSee('On each 6,000 ETB membership')->assertSee('1,800 ETB every month', false);
        $this->get('/help')->assertSee('6,000 ETB'); // FAQ "How much is the membership?"
    }

    public function test_articles_about_switched_off_features_are_hidden(): void
    {
        Features::set('wallet.cashout', false);
        $this->get('/help/cash-out')->assertNotFound();
        $this->get('/help')->assertDontSee('data-help-article="cash-out"', false);
        $this->get('/help/if-your-account-is-hacked')->assertOk()->assertDontSee('cash-out account');

        Features::set('referral.level3', false);
        $this->get('/help/how-earning-works')->assertOk()->assertSee('Level 2')->assertDontSee('Level 3');

        Features::set('signals', false);
        $help = strtolower($this->get('/help')->getContent());
        $this->assertStringNotContainsString('signal', $help);
        $this->get('/help/join-the-signals-channel')->assertNotFound();

        Features::set('site.help_centre', false);
        $this->get('/help/badges')->assertNotFound();
    }

    public function test_was_this_helpful_counts_are_shown_to_the_admin(): void
    {
        $this->postJson('/api/v1/help/cash-out/feedback', ['helpful' => true])->assertOk()->assertJsonPath('message', 'Thank you!');
        $this->postJson('/api/v1/help/cash-out/feedback', ['helpful' => true])->assertOk();
        $this->postJson('/api/v1/help/cash-out/feedback', ['helpful' => false])->assertOk();
        $this->postJson('/api/v1/help/no-such-page/feedback', ['helpful' => true])->assertNotFound();
        $this->postJson('/api/v1/help/cash-out/feedback', [])->assertUnprocessable();

        $this->actingAsAdmin();
        $row = collect($this->getJson('/api/v1/admin/help/articles')->assertOk()->json('data'))->firstWhere('slug', 'cash-out');
        $this->assertSame([2, 1], [$row['yes'], $row['no']]);

        Features::set('site.help_feedback', false);
        $this->postJson('/api/v1/help/cash-out/feedback', ['helpful' => true])->assertNotFound();
        $this->get('/help/cash-out')->assertDontSee('Was this helpful?');
    }

    public function test_the_super_admin_can_change_preview_reset_and_add_articles(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/help/articles/preview', ['title' => 'At least {cashout_min}', 'body' => 'Hi **there** <script>alert(1)</script> {pending_days} days'])
            ->assertOk()->assertJsonPath('data.title', 'At least 1,000 ETB')
            ->assertJsonPath('data.html', "<p>Hi <strong>there</strong> &lt;script&gt;alert(1)&lt;/script&gt; 14 days</p>\n");

        $this->putJson('/api/v1/admin/help/articles/cash-out', ['topic' => 'Wallet and cash-out', 'title' => 'Get your money', 'body' => 'Our own text. Minimum {cashout_min}.'])
            ->assertOk();
        $this->get('/help/cash-out')->assertSee('Get your money')->assertSee('Minimum 1,000 ETB');
        $this->assertSame('edited', collect($this->getJson('/api/v1/admin/help/articles')->json('data'))->firstWhere('slug', 'cash-out')['source']);

        $this->deleteJson('/api/v1/admin/help/articles/cash-out')->assertOk();
        $this->get('/help/cash-out')->assertSee('Cash out')->assertDontSee('Get your money');

        $this->postJson('/api/v1/admin/help/articles', ['slug' => 'opening-hours', 'topic' => 'Shopping', 'title' => 'Opening hours', 'body' => 'Mon–Sat.'])->assertOk();
        $this->get('/help/opening-hours')->assertOk()->assertSee('Mon–Sat.');
        $this->postJson('/api/v1/admin/help/articles', ['slug' => 'cash-out', 'topic' => 'Shopping', 'title' => 'x', 'body' => 'y'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/help/articles', ['slug' => '../etc', 'topic' => 'Shopping', 'title' => 'x', 'body' => 'y'])->assertUnprocessable();

        $this->putJson('/api/v1/admin/help/articles/opening-hours', ['topic' => 'Shopping', 'title' => 'Opening hours', 'body' => 'Mon–Sat.', 'is_published' => false])->assertOk();
        $this->get('/help/opening-hours')->assertNotFound();

        $this->actingAsAdmin('fulfilment');
        $this->getJson('/api/v1/admin/help/articles')->assertForbidden();
        $this->putJson('/api/v1/admin/help/articles/cash-out', ['topic' => 'x', 'title' => 'x', 'body' => 'x'])->assertForbidden();
    }

    public function test_member_pages_link_to_the_right_article(): void
    {
        $u = User::factory()->member()->create();
        $this->actingAs($u)->get('/account/wallet')->assertSee('href="'.url('/help/cash-out').'"', false);
        $this->get('/account')->assertSee('data-help-q="become-a-member"', false)->assertSee('data-help-link', false);
        Features::set('site.help_centre', false);
        $this->get('/account/wallet')->assertDontSee('data-help-q', false);
    }

    /** Quality: no number typed by hand, no unknown {token}, no link to a missing page or picture. */
    public function test_default_articles_use_live_numbers_and_only_link_to_pages_that_exist(): void
    {
        $this->seed(PagesSeeder::class);
        $tokens = array_keys(HelpTokens::all());
        $slugs = array_keys(HelpArticles::defaults());
        $this->assertGreaterThanOrEqual(20, count($slugs));
        foreach (HelpArticles::defaults() as $slug => $a) {
            $raw = $a['title'].' '.$a['body'];
            $this->assertDoesNotMatchRegularExpression('/\d[\d,.]*\s*(ETB|birr|%|days?|hours?|months?)\b/i', $raw,
                "$slug: write numbers from the settings as a {token}, never by hand.");
            preg_match_all('/\{([a-z0-9_]+)\}/', $raw, $m);
            foreach ($m[1] as $t) {
                $this->assertContains($t, $tokens, "$slug: unknown number {{$t}}.");
            }
            $this->assertContains($a['topic'], HelpArticles::TOPICS, "$slug: unknown topic.");
            preg_match_all('#\]\((/[^)\s]*)\)#', $a['body'], $links);
            foreach ($links[1] as $link) {
                if (str_starts_with($link, '/help-screens/')) {
                    $this->assertFileExists(public_path(ltrim($link, '/')), "$slug: missing picture $link");
                } elseif (str_starts_with($link, '/help/')) {
                    $this->assertContains(substr($link, 6), $slugs, "$slug links to a missing help page $link");
                } else {
                    $this->get($link)->assertOk();
                }
            }
        }
        // A folder in public/ with the same name as a page breaks the page (the web server sends /help to /help/).
        foreach (['help', 'account', 'shop', 'admin', 'p'] as $page) {
            $this->assertDirectoryDoesNotExist(public_path($page), "public/$page would hide the /$page page.");
        }
        // Every "?" on a member page points to an article that exists.
        foreach (glob(resource_path('views/member/*.blade.php')) ?: [] as $view) {
            preg_match_all("/help-link', \\['slug' => '([a-z0-9-]+)'/", (string) file_get_contents($view), $m);
            foreach ($m[1] as $slug) {
                $this->assertContains($slug, $slugs, basename($view)." links to a missing help article: $slug");
            }
        }
    }
}
