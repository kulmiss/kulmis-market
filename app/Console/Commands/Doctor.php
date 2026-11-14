<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Go-live checklist: `php artisan km:doctor`. */
class Doctor extends Command
{
    protected $signature = 'km:doctor';

    protected $description = 'Check the production setup (debug, HTTPS, keys, cron, admin 2FA, backups).';

    public function handle(): int
    {
        $beat = Cache::get('scheduler.heartbeat');
        $checks = [
            ['APP_KEY set', (string) config('app.key') !== '', 'php artisan key:generate'],
            ['APP_DEBUG is off', ! config('app.debug') || app()->environment('local'), 'APP_DEBUG=false'],
            ['APP_URL uses https', str_starts_with((string) config('app.url'), 'https://') || app()->environment('local'), 'APP_URL=https://your-domain'],
            ['Secure cookies over https', (bool) config('session.secure') || app()->environment('local'), 'SESSION_SECURE_COOKIE=true'],
            ['Database reachable', $this->dbOk(), 'check DB_* in .env'],
            ['Cron is running (scheduler heartbeat < 5 min)', $beat && Carbon::parse($beat)->gt(now()->subMinutes(5)), '* * * * * php /path/to/artisan schedule:run'],
            ['Storage link', is_link(public_path('storage')) || is_dir(public_path('storage')), 'php artisan storage:link'],
            ['Admin 2FA required', (bool) config('permissions.require_2fa'), 'ADMIN_REQUIRE_2FA=true'],
            ['A Super Admin exists', DB::table('users')->where('role', 'super_admin')->exists(), 'php artisan km:create-admin you@example.com'],
            ['Chapa secret key', (string) config('services.chapa.secret_key') !== '', 'CHAPA_SECRET_KEY=…'],
            ['Chapa webhook secret', (string) config('services.chapa.webhook_secret') !== '', 'CHAPA_WEBHOOK_SECRET=… (same as in the Chapa dashboard)'],
            ['Telegram bot token + channel', (string) config('services.telegram.bot_token') !== '' && (string) config('services.telegram.channel_id') !== '', 'TELEGRAM_BOT_TOKEN / TELEGRAM_CHANNEL_ID'],
            ['Telegram webhook secret', (string) config('services.telegram.webhook_secret') !== '', 'TELEGRAM_WEBHOOK_SECRET=<long random>'],
            ['SMS provider (not log)', config('services.sms.driver') !== 'log', 'SMS_DRIVER=http + SMS_URL / SMS_TOKEN'],
            ['Mail is configured', ! in_array(config('mail.default'), ['log', 'array'], true), 'MAIL_MAILER=smtp + MAIL_*'],
            ['Backup key', (string) config('shop.backup_key') !== '', 'php artisan km:backup-key'],
        ];
        $failed = 0;
        foreach ($checks as [$label, $ok, $fix]) {
            $ok ? $this->line("  <info>✔</info> $label") : $this->line("  <comment>✘</comment> $label  → $fix");
            $failed += $ok ? 0 : 1;
        }
        $this->newLine();
        $failed === 0 ? $this->info('All good — ready for customers.') : $this->warn("$failed item(s) to fix.");

        return self::SUCCESS;
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
