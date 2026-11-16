<?php

namespace App\Setup;

use App\Badges\ProfitGuard;
use App\Demo\Demo;
use App\Models\User;
use App\Support\Branding;
use App\Support\Features;
use App\Support\Settings;
use App\Telegram\TelegramClient;

/**
 * The Super Admin's first-time setup (5 pages) and the "still to do" list on
 * the dashboard afterwards. Everything it changes can be changed later in
 * Settings; secrets stay in .env and are never shown back.
 */
final class SetupWizard
{
    public const PAGES = 5;

    /** Settings on page 2 (your shop) and page 3 (prices and earnings). */
    public const SHOP = ['site.name', 'appearance.shop_color', 'currency.code', 'currency.symbol', 'site.whatsapp', 'site.telegram_support', 'site.support_email'];

    public const PRICES = ['membership.price', 'pricing.member_discount_bps', 'signals.guest_price', 'referral.rate.membership.l1',
        'referral.rate.membership.l2', 'referral.rate.membership.l3', 'wallet.cashout_min'];

    /** Main switches shown as cards on page 5. */
    public const FEATURES = ['shop', 'membership', 'signals', 'referral', 'wallet', 'wallet.cashout', 'badges', 'shop.gift_cards', 'site.help_centre'];

    public static function needed(User $user): bool
    {
        return $user->isSuperAdmin() && Features::enabled('admin.setup_wizard') && ! Settings::get('setup.finished');
    }

    /** @return array<string, mixed> */
    public static function state(User $user): array
    {
        $values = [];
        foreach ([...self::SHOP, ...self::PRICES] as $key) {
            $values[$key] = Settings::get($key);
        }
        $guard = ProfitGuard::worstCase();

        return [
            'step' => max(1, min(self::PAGES, Settings::int('setup.step'))),
            'finished' => (bool) Settings::get('setup.finished'),
            'on' => Features::enabled('admin.setup_wizard'),
            'security' => [
                'two_factor' => $user->hasTwoFactor(),
                'recovery_codes_left' => count((array) ($user->two_factor_recovery_codes ?? [])),
                'backup_key' => (string) config('shop.backup_key') !== '',
            ],
            'values' => $values,
            'logo_url' => Branding::logoUrl(),
            'guard' => ['keep' => $guard['keep'], 'total_bps' => $guard['total'], 'max_bps' => $guard['max']],
            'services' => self::services(),
            'features' => array_map(fn (string $k) => [
                'key' => $k, 'on' => Features::enabled($k),
                'label' => (string) (config("features.$k")[0] ?? $k),
                'description' => (string) (config("features.$k")[4] ?? ''),
            ], array_values(array_filter(self::FEATURES, fn ($k) => Features::exists($k)))),
            'demo' => ['exists' => Demo::exists()],
            'checklist' => self::checklist($user),
        ];
    }

    /** @return list<array{key:string, name:string, connected:bool, detail:string, can_test:bool, help:string}> */
    public static function services(): array
    {
        $defaults = config('settings');
        $paymentsSet = Settings::string('payments.bank_details') !== $defaults['payments.bank_details'][1]
            && Settings::string('payments.telebirr_details') !== $defaults['payments.telebirr_details'][1];
        $mailer = (string) config('mail.default');
        $telegram = app(TelegramClient::class)->configured();

        return [
            ['key' => 'payments', 'name' => 'Telebirr and bank transfer', 'connected' => $paymentsSet,
                'detail' => $paymentsSet ? 'Your own account details are saved.' : 'Still the example account details. Type your real ones in Settings → Payments.',
                'can_test' => false, 'help' => 'setup-payments'],
            ['key' => 'telegram', 'name' => 'Telegram bot', 'connected' => $telegram,
                'detail' => $telegram ? 'TELEGRAM_BOT_TOKEN is set in .env.' : 'Add TELEGRAM_BOT_TOKEN and TELEGRAM_CHANNEL_ID to .env.',
                'can_test' => $telegram, 'help' => 'setup-telegram'],
            ['key' => 'email', 'name' => 'Email', 'connected' => ! in_array($mailer, ['log', 'array'], true),
                'detail' => in_array($mailer, ['log', 'array'], true) ? 'Emails only go to the log. Add your MAIL_ settings to .env.' : 'MAIL_ settings are in .env.',
                'can_test' => ! in_array($mailer, ['log', 'array'], true), 'help' => 'setup-email'],
            ['key' => 'sms', 'name' => 'SMS', 'connected' => config('services.sms.driver') === 'http',
                'detail' => config('services.sms.driver') === 'http' ? 'SMS_URL and SMS_TOKEN are set in .env.' : 'Not connected yet: SMS only go to the log. Email and Telegram still work.',
                'can_test' => config('services.sms.driver') === 'http', 'help' => 'setup-sms'],
        ];
    }

    /**
     * What is still not done after setup, shown on the dashboard until all are done.
     *
     * @return list<array{key:string, text:string, done:bool, link:string}>
     */
    public static function checklist(User $user): array
    {
        $s = collect(self::services())->keyBy('key');
        $items = [
            ['key' => 'two_factor', 'text' => 'Two-step sign-in is on', 'done' => $user->hasTwoFactor(), 'link' => '/settings'],
            ['key' => 'backup_key', 'text' => 'Backup key is set (BACKUP_KEY in .env)', 'done' => (string) config('shop.backup_key') !== '', 'link' => '/guide/setup-backups'],
            ['key' => 'payments', 'text' => 'Your Telebirr and bank details are saved', 'done' => $s['payments']['connected'], 'link' => '/config'],
            ['key' => 'email', 'text' => 'Email is connected', 'done' => $s['email']['connected'], 'link' => '/guide/setup-email'],
        ];
        if (Features::enabled('signals')) {
            $items[] = ['key' => 'telegram', 'text' => 'Telegram bot is connected', 'done' => $s['telegram']['connected'], 'link' => '/guide/setup-telegram'];
        }
        $items[] = ['key' => 'demo', 'text' => 'Demo data is deleted', 'done' => ! Demo::exists(), 'link' => '/demo'];

        return $items;
    }
}
