<?php

namespace Tests\Feature\Platform;

use App\Help\AdminGuide;
use App\Help\HelpTokens;
use App\Setup\SetupWizard;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Super Admin guide: in the admin (Guide + "?" on every page), and as one PDF. */
class AdminGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_read_the_guide_with_live_numbers(): void
    {
        $this->getJson('/api/v1/admin/guide')->assertUnauthorized();
        $this->actingAsAdmin('fulfilment');
        $list = $this->getJson('/api/v1/admin/guide')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(25, count($list));
        $this->assertSame(['1. Setup', '2. Daily use', '3. Problems and answers', '4. Quick reference'], array_values(array_unique(array_column($list, 'part'))));

        $page = $this->getJson('/api/v1/admin/guide/daily-wallets-cash-outs')->assertOk();
        $this->assertStringContainsString('<td>1,000 ETB</td>', $page->json('data.html'));
        $this->assertStringContainsString('src="/api/v1/admin/guide/images/admin-cash-outs.jpg"', $page->json('data.html'));
        Settings::set('wallet.cashout_min', 250_000);
        $this->assertStringContainsString('<td>2,500 ETB</td>', $this->getJson('/api/v1/admin/guide/daily-wallets-cash-outs')->json('data.html'));

        $cheat = $this->getJson('/api/v1/admin/guide/quick-reference')->json('data.html');
        $this->assertStringContainsString('Membership price per month', $cheat);
        $this->assertStringContainsString('<strong>5,000 ETB</strong>', $cheat);
        $this->assertStringContainsString('href="/admin/guide/', $this->getJson('/api/v1/admin/guide/setup-first-sign-in')->json('data.html'));
        $this->getJson('/api/v1/admin/guide/nope')->assertNotFound();
    }

    public function test_pictures_are_served_to_staff_only_and_never_other_files(): void
    {
        $this->getJson('/api/v1/admin/guide/images/admin-dashboard.jpg')->assertUnauthorized();
        $this->actingAsAdmin('support');
        $this->get('/api/v1/admin/guide/images/admin-dashboard.jpg')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/api/v1/admin/guide/images/..%2F..%2F.env')->assertNotFound();
        $this->get('/api/v1/admin/guide/images/nothing.jpg')->assertNotFound();
    }

    public function test_the_whole_guide_as_one_pdf(): void
    {
        $this->actingAsAdmin();
        $res = $this->get('/api/v1/admin/guide/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());
        $out = 'storage/framework/testing/guide.pdf';
        @mkdir(dirname(base_path($out)), 0777, true);
        $this->artisan('km:guide-pdf', ['--out' => $out])->assertSuccessful();
        $this->assertStringStartsWith('%PDF', (string) file_get_contents(base_path($out)));
        @unlink(base_path($out));
        $this->assertFileExists(base_path('docs/admin-guide.pdf'));
    }

    /** "?" on every admin page: each screen in the menu has a guide page. */
    public function test_every_admin_screen_has_a_guide_page(): void
    {
        preg_match_all("/url: '([^']+)'/", (string) file_get_contents(base_path('admin/src/components/layout/data/sidebar-data.ts')), $m);
        $this->assertGreaterThan(30, count($m[1]));
        foreach ($m[1] as $url) {
            $this->assertNotNull(AdminGuide::forScreen($url), "Admin screen $url has no guide page: add it to a page's \"pages:\" line.");
        }
        $this->assertSame('daily-wallets-cash-outs', AdminGuide::forScreen('/cash-outs/12'));
        $this->assertSame('daily-signals', AdminGuide::forScreen('/signals/telegram'));
        $this->assertSame('daily-dashboard-money-health', AdminGuide::forScreen('/'));
    }

    /** A test fails if a help link points to a missing page, picture or number. */
    public function test_guide_links_pictures_and_numbers_all_exist(): void
    {
        $slugs = array_column(AdminGuide::pages(), 'slug');
        $tokens = [...array_keys(HelpTokens::all()), 'money_rules'];
        $files = [...glob(base_path('docs/admin-guide/*.md')) ?: []];
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            preg_match_all('/\]\(([a-z0-9-]+)\.md(#[a-z0-9-]+)?\)/', $text, $links);
            foreach ($links[1] as $l) {
                $this->assertContains($l, $slugs, basename($file)." links to a missing guide page: $l.md");
            }
            preg_match_all('#\]\(\.\./screenshots/([^)]+)\)#', $text, $pics);
            foreach ($pics[1] as $pic) {
                $this->assertFileExists(base_path('docs/screenshots/'.$pic), basename($file)." shows a missing picture: $pic");
            }
            preg_match_all('/(?<![`$])\{([a-z0-9_]+)\}(?!`)/', (string) preg_replace('/```.*?```|`[^`]*`/s', '', $text), $nums);
            foreach ($nums[1] as $t) {
                $this->assertContains($t, $tokens, basename($file)." uses an unknown number {{$t}}.");
            }
        }
        // Links from the setup wizard and the "still to do" list.
        $this->actingAsAdmin();
        foreach (SetupWizard::services() as $s) {
            $this->assertContains($s['help'], $slugs, "Setup wizard links to a missing guide page: {$s['help']}");
        }
        foreach (SetupWizard::checklist(auth()->user()) as $item) {
            if (str_starts_with($item['link'], '/guide/')) {
                $this->assertContains(substr($item['link'], 7), $slugs);
            }
        }
        $this->assertStringContainsString('setup-backups', (string) file_get_contents(base_path('admin/src/features/setup/index.tsx')));
    }
}
