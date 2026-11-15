<?php

namespace Tests\Feature\Security;

use App\Console\Commands\BackupVerify;
use App\Models\User;
use App\Support\Csp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class OpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_fails_loudly_on_an_unsafe_production_setup(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => true, 'app.url' => 'http://shop.example', 'session.secure' => false, 'security.staff_password_min' => 5,
            'permissions.require_2fa' => false, 'shop.backup_key' => '']);
        User::factory()->superAdmin()->create(); // no 2FA

        $this->artisan('km:doctor')
            ->expectsOutputToContain('✘ Debug mode off')
            ->expectsOutputToContain('✘ Site uses https')
            ->expectsOutputToContain('✘ Every staff account has 2FA on')
            ->expectsOutputToContain('✘ Staff passwords ≥ 12 characters')
            ->expectsOutputToContain('✘ Backup key set')
            ->assertFailed();
    }

    public function test_admin_ip_lock(): void
    {
        config(['security.admin_allowed_ips' => '196.188.0.0/16, 10.1.2.3']);
        $this->withServerVariables(['REMOTE_ADDR' => '41.0.0.9'])->get('/admin')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '41.0.0.9'])->postJson('/api/v1/admin/auth/login', ['login' => 'a', 'password' => 'b'])->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '196.188.44.5'])->postJson('/api/v1/admin/auth/login', ['login' => 'a', 'password' => 'b'])->assertUnprocessable();

        config(['security.admin_allowed_ips' => '']);
        $this->withServerVariables(['REMOTE_ADDR' => '41.0.0.9'])->postJson('/api/v1/admin/auth/login', ['login' => 'a', 'password' => 'b'])->assertUnprocessable();
    }

    private function sqliteBackup(int $credit, int $debit): string
    {
        $db = tempnam(sys_get_temp_dir(), 'kmdb');
        $pdo = new PDO('sqlite:'.$db);
        foreach (BackupVerify::REQUIRED_TABLES as $t) {
            $pdo->exec("create table $t (id integer primary key".($t === 'ledger_postings' ? ', amount integer' : '').')');
        }
        $pdo->exec("insert into ledger_postings (amount) values ($debit), (-$credit)");
        $pdo->exec('insert into users (id) values (1), (2)');
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $db]]);
        File::delete(File::glob(storage_path('app/backups/kulmis-*.enc')));
        $this->artisan('km:backup', ['--no-files' => true, '--connection' => 'backup_test'])->assertSuccessful();

        return $db;
    }

    public function test_restore_test_opens_the_backup_and_checks_the_ledger(): void
    {
        config(['shop.backup_key' => 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen())]);
        $db = $this->sqliteBackup(500, 500);
        $this->artisan('km:backup-verify')->expectsOutputToContain('ledger balances')->expectsOutputToContain('Backup OK')->assertSuccessful();

        // Wrong key: fails.
        config(['shop.backup_key' => 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen())]);
        $this->artisan('km:backup-verify')->expectsOutputToContain('FAILED')->assertFailed();
        File::delete($db);
    }

    public function test_restore_test_catches_an_unbalanced_ledger(): void
    {
        config(['shop.backup_key' => 'base64:'.base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen())]);
        $db = $this->sqliteBackup(500, 499);
        $this->artisan('km:backup-verify')->expectsOutputToContain('does not balance')->assertFailed();
        File::delete(array_merge([$db], File::glob(storage_path('app/backups/kulmis-*.enc'))));
    }

    public function test_local_docker_uses_port_8090_and_runs_php_as_your_user(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));
        $this->assertStringContainsString('${APP_PORT:-8090}', $compose);
        $this->assertStringContainsString('127.0.0.1:', $compose);
        $docker = (string) file_get_contents(base_path('docker/php/Dockerfile'));
        $this->assertStringContainsString('USER www-data', $docker);
        $this->assertFileExists(base_path('.github/dependabot.yml'));
    }

    public function test_cached_pages_get_the_same_csp_from_nginx_and_apache(): void
    {
        config(['app.url' => 'http://localhost']);
        $csp = Csp::storefront();
        $this->assertStringContainsString('Content-Security-Policy "'.$csp.'"', (string) file_get_contents(base_path('docker/nginx/default.conf')),
            'Update the CSP in docker/nginx/default.conf (page-cache block) — it must match Csp::storefront().');
        $this->assertStringContainsString('Content-Security-Policy "'.$csp.'" env=KM_CACHED', (string) file_get_contents(public_path('.htaccess')),
            'Update the CSP in public/.htaccess — it must match Csp::storefront().');
    }
}
