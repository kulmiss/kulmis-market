<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Features;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Go-live and security check: `php artisan km:doctor`.
 * Red ✘ = unsafe (the command fails, so deploy scripts can stop on it).
 * Yellow ! = setup still missing (works, but something won't happen).
 */
class Doctor extends Command
{
    protected $signature = 'km:doctor';

    protected $description = 'Security and go-live checks. Fails (exit 1) if anything is unsafe.';

    public function handle(): int
    {
        $prod = app()->isProduction();
        $local = app()->environment('local', 'testing');
        $beat = Cache::get('scheduler.heartbeat');
        $env = base_path('.env');
        $backup = collect(File::glob(storage_path('app/backups/kulmis-*.enc')))->sort()->last();
        $staffWithout2fa = User::query()->where('role', '!=', User::ROLE_CUSTOMER)->whereNull('two_factor_confirmed_at')->count();

        // [label, ok, how to fix]
        $security = [
            ['APP_KEY set', (string) config('app.key') !== '', 'php artisan key:generate'],
            ['Debug mode off', ! config('app.debug') || $local, 'APP_DEBUG=false'],
            ['Running as production', $prod || $local, 'APP_ENV=production'],
            ['Site uses https', str_starts_with((string) config('app.url'), 'https://') || $local, 'APP_URL=https://your-domain (and a TLS certificate)'],
            ['Cookies are Secure (https only)', (bool) config('session.secure') || $local, 'leave SESSION_SECURE_COOKIE empty with an https APP_URL'],
            ['Session data encrypted', (bool) config('session.encrypt'), 'SESSION_ENCRYPT=true'],
            ['Admin 2FA required', (bool) config('permissions.require_2fa'), 'ADMIN_REQUIRE_2FA=true'],
            ['Every staff account has 2FA on', $staffWithout2fa === 0, "$staffWithout2fa staff account(s) without 2FA: sign in and set it up, or suspend them"],
            ['Staff passwords ≥ 12 characters', (int) config('security.staff_password_min') >= 12, 'ADMIN_PASSWORD_MIN=12'],
            ['Breached-password check on', (bool) config('security.pwned_check') || ! $prod, 'PASSWORD_PWNED_CHECK=true'],
            ['Backup key set (and not the app key)', ((string) config('shop.backup_key') !== '' && config('shop.backup_key') !== config('app.key')) || ! $prod, 'php artisan km:backup-key'],
            ['A backup in the last 26 hours', $backup !== null && filemtime($backup) > now()->subHours(26)->getTimestamp() || ! $prod, 'cron runs km:backup nightly; check the scheduler'],
            ['.env not readable by other users', ! is_file($env) || (fileperms($env) & 0o004) === 0 || $local, 'chmod 600 .env'],
            ['No secrets or dumps in public/', $this->publicIsClean(), 'remove .env / *.sql / *.zip from public/'],
            ['PHP errors not shown to visitors', ! filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) || $local, 'display_errors=Off in php.ini'],
            ['PHP version hidden', ! filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN) || $local, 'expose_php=Off in php.ini'],
            ['Log level not debug', config('logging.channels.'.config('logging.default').'.level', 'debug') !== 'debug' || ! $prod, 'LOG_LEVEL=info'],
            ['Telegram webhook secret (when the bot is on)', Features::disabled('telegram_bot') || (string) config('services.telegram.webhook_secret') !== '' || ! $prod, 'TELEGRAM_WEBHOOK_SECRET=<long random>'],
        ];
        $setup = [
            ['Database reachable', $this->dbOk(), 'check DB_* in .env'],
            ['Cron is running (scheduler heartbeat < 5 min)', $beat && Carbon::parse($beat)->gt(now()->subMinutes(5)), '* * * * * php /path/to/artisan schedule:run'],
            ['Storage link', is_link(public_path('storage')) || is_dir(public_path('storage')), 'php artisan storage:link'],
            ['A Super Admin exists', DB::table('users')->where('role', 'super_admin')->exists(), 'php artisan km:create-admin you@example.com'],
            ['Telegram bot token + channel', (string) config('services.telegram.bot_token') !== '' && (string) config('services.telegram.channel_id') !== '', 'TELEGRAM_BOT_TOKEN / TELEGRAM_CHANNEL_ID'],
            ['Owner alerts chat (Telegram)', (string) config('services.telegram.admin_chat_id') !== '', 'TELEGRAM_ADMIN_CHAT_ID=<your chat id>'],
            ['SMS provider (not log)', config('services.sms.driver') !== 'log', 'SMS_DRIVER=http + SMS_URL / SMS_TOKEN'],
            ['Mail is configured', ! in_array(config('mail.default'), ['log', 'array'], true), 'MAIL_MAILER=smtp + MAIL_*'],
            ['Admin IP lock (optional)', (string) config('security.admin_allowed_ips') !== '', 'ADMIN_ALLOWED_IPS=your office/home IP (optional)'],
        ];

        $this->line('<options=bold>Security</>');
        $unsafe = 0;
        foreach ($security as [$label, $ok, $fix]) {
            $ok ? $this->line("  <info>✔</info> $label") : $this->line("  <fg=red>✘ $label</>  → $fix");
            $unsafe += $ok ? 0 : 1;
        }
        $this->newLine();
        $this->line('<options=bold>Setup</>');
        $todo = 0;
        foreach ($setup as [$label, $ok, $fix]) {
            $ok ? $this->line("  <info>✔</info> $label") : $this->line("  <comment>! $label</comment>  → $fix");
            $todo += $ok ? 0 : 1;
        }
        $this->newLine();
        if ($unsafe > 0) {
            $this->error("$unsafe security item(s) are unsafe — fix them before going live.");

            return self::FAILURE;
        }
        $todo === 0 ? $this->info('All good — ready for customers.') : $this->warn("Safe. $todo setup item(s) still to do.");

        return self::SUCCESS;
    }

    private function publicIsClean(): bool
    {
        foreach (['.env', '*.sql', '*.sqlite', '*.zip', '*.tar.gz', '*.bak', 'phpinfo.php'] as $pattern) {
            if (File::glob(public_path($pattern)) !== []) {
                return false;
            }
        }

        return true;
    }

    private function dbOk(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
