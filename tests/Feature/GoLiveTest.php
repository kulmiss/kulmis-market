<?php

namespace Tests\Feature;

use App\Console\Commands\Backup;
use App\Models\Page;
use App\Support\Csp;
use Database\Seeders\PagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class GoLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_and_admin_send_a_strict_csp(): void
    {
        $res = $this->get('/');
        $csp = (string) $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString(Csp::themeHash(), $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        // The inline theme script on the page is exactly the hashed one.
        $this->assertStringContainsString('<script>'.Csp::THEME_SCRIPT.'</script>', (string) $res->getContent());
        $this->assertStringNotContainsString('fonts.googleapis', (string) $res->getContent());
    }

    public function test_default_texts_are_seeded_once_and_use_current_settings(): void
    {
        $this->seed(PagesSeeder::class);
        $this->assertSame(['membership-terms', 'privacy', 'referral-rules', 'refund-shipping', 'terms'], Page::query()->orderBy('slug')->pluck('slug')->all());
        $this->assertStringContainsString('Proclamation No. 1321/2024', Page::query()->where('slug', 'privacy')->value('body'));
        $this->assertStringContainsString('5,000 ETB per month', Page::query()->where('slug', 'membership-terms')->value('body'));

        Page::query()->where('slug', 'terms')->update(['body' => 'My own words']);
        $this->seed(PagesSeeder::class);
        $this->assertSame('My own words', Page::query()->where('slug', 'terms')->value('body'), 'never overwrites edits');
        $this->get('/p/privacy')->assertOk()->assertSee('Ethiopian Communications Authority');
    }

    public function test_encrypted_backup_round_trip(): void
    {
        $db = tempnam(sys_get_temp_dir(), 'kmdb');
        File::put($db, 'SQLite format 3 test-bytes');
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $db],
            'shop.backup_key' => 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen())]);

        $this->artisan('km:backup', ['--no-files' => true, '--connection' => 'backup_test'])->assertSuccessful();
        $file = collect(File::glob(storage_path('app/backups/kulmis-*.enc')))->last();
        $this->assertNotNull($file);
        $this->assertStringNotContainsString('test-bytes', (string) File::get($file), 'content is encrypted');

        $out = sys_get_temp_dir().'/km-restore-'.uniqid().'.tar.gz';
        Backup::decryptFile($file, $out, Backup::key());
        $list = Process::run(['tar', '-xzOf', $out, 'database.sql'])->throw()->output();
        $this->assertStringContainsString('test-bytes', $list);

        // Wrong key fails loudly.
        config(['shop.backup_key' => 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen())]);
        try {
            Backup::decryptFile($file, $out.'.2', Backup::key());
            $this->fail('decrypted with wrong key');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        File::delete([$file, $out, $out.'.2', $db]);
    }

    public function test_doctor_runs(): void
    {
        $this->artisan('km:doctor')->expectsOutputToContain('APP_KEY set')->assertSuccessful();
    }
}
