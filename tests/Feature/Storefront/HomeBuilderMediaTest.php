<?php

namespace Tests\Feature\Storefront;

use App\Demo\Demo;
use App\Home\HomeMediaStore;
use App\Home\HomePublisher;
use App\Home\HomeTemplates;
use App\Home\Sections;
use App\Home\SectionTypes;
use App\Home\VideoLink;
use App\Models\HomeMedia;
use App\Models\HomePageVersion;
use App\Models\Product;
use App\Models\Testimonial;
use App\Models\User;
use App\Storefront\PageCache;
use App\Support\Csp;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomeBuilderMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        PageCache::clear();
        parent::tearDown();
    }

    /** A JPEG with EXIF/GPS-like text inside, so we can see it is gone after re-encoding. */
    private function photoWithMetadata(): UploadedFile
    {
        $img = imagecreatetruecolor(1200, 900);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 120, 60));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        $app1 = "Exif\0\0SECRET-GPS-9.0302N-38.7469E";
        $jpeg = "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
        $path = tempnam(sys_get_temp_dir(), 'km').'.jpg';
        file_put_contents($path, $jpeg);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    private function publish(array $sections): void
    {
        $by = User::factory()->role('super_admin')->create();
        app(HomePublisher::class)->saveDraft($sections, [], HomePageVersion::draft()->revision ?? 0, $by);
        app(HomePublisher::class)->publish($by);
    }

    public function test_pictures_are_re_encoded_in_three_formats_without_metadata_and_need_a_description(): void
    {
        $this->actingAsAdmin();
        $this->post('/api/v1/admin/home-page/media', ['kind' => 'image', 'file' => $this->photoWithMetadata()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('alt');
        $this->post('/api/v1/admin/home-page/media', ['kind' => 'image', 'alt' => 'Baskets', 'file' => UploadedFile::fake()->create('x.svg', 5, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $id = $this->post('/api/v1/admin/home-page/media', ['kind' => 'image', 'alt' => 'Orange baskets', 'file' => $this->photoWithMetadata()], ['Accept' => 'application/json'])
            ->assertCreated()->json('media.id');
        $m = HomeMedia::query()->findOrFail($id);
        $this->assertSame([400, 800, 1200], $m->widths);
        $this->assertContains('webp', $m->formats);
        foreach ($m->widths as $w) {
            foreach ($m->formats as $f) {
                Storage::disk('public')->assertExists("{$m->path}-$w.$f");
                $this->assertStringNotContainsString('SECRET-GPS', (string) Storage::disk('public')->get("{$m->path}-$w.$f"));
            }
        }

        // On the page: <picture> with srcset, lazy below the top.
        $it = Sections::make('image_text');
        $it['data']['image'] = $id;
        $this->publish([Sections::make('hero'), $it]);
        auth()->guard('web')->logout();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<source type="image/webp" srcset="[^"]+-400\.webp 400w, [^"]+-800\.webp 800w, [^"]+-1200\.webp 1200w"#', $html);
        $this->assertStringContainsString('alt="Orange baskets"', $html);
        $this->assertMatchesRegularExpression('#alt="Orange baskets"\s+class="[^"]*"\s+loading="lazy"#', $html);
    }

    public function test_a_picture_without_description_blocks_publishing_and_used_media_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $id = $this->post('/api/v1/admin/home-page/media', ['kind' => 'image', 'alt' => 'Baskets', 'file' => $this->photoWithMetadata()], ['Accept' => 'application/json'])->json('media.id');
        HomeMedia::query()->whereKey($id)->update(['alt' => '']);
        $it = Sections::make('image_text');
        $it['data']['image'] = $id;
        $this->putJson('/api/v1/admin/home-page/draft', ['sections' => [Sections::make('hero'), $it], 'revision' => 0])->assertOk();
        $this->postJson('/api/v1/admin/home-page/publish')->assertUnprocessable()->assertJsonFragment(['Picture + text → Picture needs a short description (alt text) for people who cannot see it.']);

        $this->putJson("/api/v1/admin/home-page/media/$id", ['alt' => 'Woven baskets'])->assertOk();
        $this->postJson('/api/v1/admin/home-page/publish')->assertOk();
        $this->deleteJson("/api/v1/admin/home-page/media/$id")->assertUnprocessable()->assertJsonFragment(['It is used on the live page. Take it out there first.']);
    }

    public function test_video_links_are_only_youtube_or_vimeo_and_load_after_a_tap(): void
    {
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0', VideoLink::parse('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10')['embed']);
        $this->assertNotNull(VideoLink::parse('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertNotNull(VideoLink::parse('https://www.youtube.com/shorts/dQw4w9WgXcQ'));
        $this->assertSame('https://player.vimeo.com/video/76979871?autoplay=1&dnt=1', VideoLink::parse('https://vimeo.com/76979871')['embed']);
        foreach (['javascript:alert(1)', 'https://evil.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=<script>', 'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ', ''] as $bad) {
            $this->assertNull(VideoLink::parse($bad), $bad);
        }

        $v = Sections::make('video');
        $v['data']['url'] = 'https://youtu.be/dQw4w9WgXcQ';
        $v['data']['title'] = 'How we weave';
        $this->publish([Sections::make('hero'), $v]);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('data-video="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&amp;rel=0"', $html);
        $this->assertStringNotContainsString('<iframe', $html, 'nothing from YouTube until a tap');
        $this->assertStringContainsString('Watch on YouTube', $html);
        $this->assertStringContainsString('frame-src https://www.youtube-nocookie.com https://player.vimeo.com', Csp::storefront());
        $this->assertStringNotContainsString("'unsafe-inline'", str_replace("style-src-attr 'unsafe-inline'", '', Csp::storefront()), 'no inline scripts or styles allowed');

        $bad = Sections::make('video');
        $bad['data']['url'] = 'https://example.com/movie';
        $this->assertContains('Video → YouTube or Vimeo link is not a YouTube or Vimeo video link.', array_column(Sections::problems([$bad], Sections::seo([]))['errors'], 'message'));
    }

    public function test_uploaded_videos_are_re_encoded_without_metadata_and_get_a_poster(): void
    {
        if (! HomeMediaStore::videoEnabled()) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        $src = tempnam(sys_get_temp_dir(), 'km').'.mp4';
        Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=10', '-t', '2', '-metadata', 'title=SECRET-TITLE',
            '-metadata', 'location=+09.03+038.74/', '-pix_fmt', 'yuv420p', $src])->throw();
        $this->assertStringContainsString('SECRET-TITLE', (string) file_get_contents($src));

        $this->actingAsAdmin();
        $fake = UploadedFile::fake()->createWithContent('clip.mp4', 'not a video at all');
        $this->post('/api/v1/admin/home-page/media', ['kind' => 'video', 'alt' => 'Weaving', 'file' => $fake], ['Accept' => 'application/json'])->assertUnprocessable();

        $res = $this->post('/api/v1/admin/home-page/media', ['kind' => 'video', 'alt' => 'Weaving on a loom', 'file' => new UploadedFile($src, 'clip.mp4', 'video/mp4', null, true)], ['Accept' => 'application/json'])
            ->assertCreated();
        $video = HomeMedia::query()->findOrFail($res->json('media.id'));
        $this->assertSame([320, 240], [$video->width, $video->height]);
        $bytes = (string) Storage::disk('public')->get($video->path);
        $this->assertStringNotContainsString('SECRET-TITLE', $bytes);
        $this->assertStringNotContainsString('038.74', $bytes);
        $this->assertNotNull($res->json('poster.id'), 'a poster picture is made from the video');

        $v = Sections::make('video');
        $v['data'] = [...$v['data'], 'source' => 'upload', 'video' => $video->id, 'poster' => $res->json('poster.id')];
        $this->publish([Sections::make('hero'), $v]);
        auth()->guard('web')->logout();
        $html = $this->get('/')->getContent();
        $this->assertMatchesRegularExpression('#<video [^>]*preload="none"[^>]*poster="[^"]+"#s', $html);

        config(['shop.ffmpeg' => '']);
        $this->assertFalse(HomeMediaStore::videoEnabled());
    }

    public function test_testimonials_show_only_when_confirmed_real_and_photos_only_with_permission(): void
    {
        $this->actingAsAdmin();
        $real = $this->post('/api/v1/admin/home-page/testimonials', ['name' => 'Almaz', 'city' => 'Adama', 'text' => 'Lovely <b>scarf</b>', 'confirmed_real' => '1',
            'photo' => UploadedFile::fake()->image('a.jpg', 600, 600)], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame(['Photo hidden: no permission ticked'], $real['not_shown']);
        $unsure = $this->postJson('/api/v1/admin/home-page/testimonials', ['name' => 'Kebede', 'text' => 'Made this one up'])->assertCreated()->json('data');
        $this->assertSame(['Tick "A real customer said this" to show it'], $unsure['not_shown']);

        $this->publish([Sections::make('hero'), Sections::make('testimonials')]);
        auth()->guard('web')->logout();
        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('Lovely &lt;b&gt;scarf&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('Made this one up', $html);
        $this->assertStringNotContainsString('/storage/testimonials/', $html, 'no photo without permission');

        $this->actingAsAdmin();
        $this->post("/api/v1/admin/home-page/testimonials/{$real['id']}", [...$real, 'photo' => null, 'confirmed_real' => '1', 'photo_ok' => '1', 'is_active' => '1', 'position' => 0], ['Accept' => 'application/json'])->assertOk();
        PageCache::clear();
        auth()->guard('web')->logout();
        $this->assertStringContainsString('/storage/testimonials/', $this->get('/')->getContent());
        $this->assertSame(0, Testimonial::query()->where('is_demo', true)->count(), 'never any made-up quotes');
    }

    public function test_investor_demo_template_uses_only_demo_rows_that_hide_and_delete_with_demo_data(): void
    {
        Product::factory()->count(3)->create(['is_featured' => true]);
        $admin = $this->actingAsAdmin();
        Settings::set('demo.show', false);
        $this->postJson('/api/v1/admin/home-page/templates/investor_demo')->assertUnprocessable();

        Settings::set('demo.show', true);
        $sections = $this->postJson('/api/v1/admin/home-page/templates/investor_demo')->assertOk()->json('sections');
        $types = array_unique(array_column($sections, 'type'));
        $this->assertEqualsCanonicalizing(array_keys(SectionTypes::all()), array_values($types), 'every kind of section');
        $this->assertSame(6, HomeMedia::query()->where('is_demo', true)->count());
        $this->assertSame(3, Testimonial::query()->where('is_demo', true)->count());
        $this->postJson('/api/v1/admin/home-page/templates/investor_demo')->assertOk();
        $this->assertSame(6, HomeMedia::query()->where('is_demo', true)->count(), 'not added twice');

        $this->putJson('/api/v1/admin/home-page/draft', ['sections' => $sections, 'revision' => 0])->assertOk()->assertJsonPath('problems.errors', []);
        $this->postJson('/api/v1/admin/home-page/publish')->assertOk();
        auth()->guard('web')->logout();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Selam', $html);
        $this->assertStringContainsString('>Demo</span>', $html);
        $this->assertStringContainsString('Demo photo:', $html);

        Settings::set('demo.show', false);
        PageCache::clear();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Selam', $html, 'demo quotes hidden');
        $this->assertStringNotContainsString('Demo photo:', $html, 'demo pictures hidden');

        Settings::set('demo.show', true);
        $files = HomeMedia::query()->where('is_demo', true)->get()->flatMap(fn ($m) => collect($m->widths)->map(fn ($w) => "{$m->path}-$w.webp"))->all();
        Demo::purge($admin->id);
        $this->assertSame(0, HomeMedia::query()->count() + Testimonial::query()->count());
        foreach ($files as $f) {
            Storage::disk('public')->assertMissing($f);
        }
    }

    public function test_other_templates_publish_without_errors(): void
    {
        $this->actingAsAdmin();
        foreach (['current', 'shop_first', 'membership_first', 'story_first'] as $key) {
            $s = HomeTemplates::sections($key);
            $this->assertSame([], Sections::problems($s, Sections::seo([]))['errors'], $key);
        }
        $this->assertSame(['current', 'shop_first', 'membership_first', 'story_first', 'investor_demo'], array_keys(HomeTemplates::list()));
    }
}
