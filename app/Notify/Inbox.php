<?php

namespace App\Notify;

use App\Jobs\SendWebPush;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Features;

/**
 * The member's notification centre (the bell). Writes are tiny and happen in
 * the same transaction as the money or order change, so a rolled-back change
 * never leaves a notification behind.
 */
final class Inbox
{
    /** Easy names for the settings page. */
    public const TYPES = [
        'earning' => 'Team earnings', 'team' => 'Someone joined my team', 'badge' => 'New badges',
        'bonus' => 'Badge bonus paid', 'order' => 'My orders', 'renewal' => 'My membership',
        'cashout' => 'My cash-outs', 'goal' => 'My monthly goal', 'signal' => 'New trading signals',
    ];

    /** The feature each kind of news belongs to: when it is switched off, the option is hidden and nothing is sent. */
    public const FEATURES = [
        'earning' => 'referral', 'team' => 'referral', 'badge' => 'badges', 'bonus' => 'badges.bonus',
        'order' => 'shop', 'renewal' => 'membership', 'cashout' => 'wallet.cashout', 'goal' => 'account.goals',
        'signal' => 'signals',
    ];

    /** @return array<string, string> the kinds of news whose feature is on (type => easy name) */
    public static function types(): array
    {
        return array_filter(self::TYPES, fn (string $type) => Features::enabled(self::FEATURES[$type]), ARRAY_FILTER_USE_KEY);
    }

    /** Pages a notification can link to, and the feature each needs. */
    private const URL_FEATURES = [
        '/account/wallet' => 'wallet', '/account/badges' => 'badges', '/account/signals' => 'signals', '/account/orders' => 'shop',
        '/account/team' => 'referral', '/account/earnings' => 'referral', '/account/share' => 'referral.share_kit',
        '/signals' => 'signals', '/shop' => 'shop', '/membership' => 'membership',
    ];

    /** Shown in the bell list? (its kind of news belongs to a feature that is on) */
    public static function visible(string $type): bool
    {
        $pref = self::PREF[$type] ?? $type;

        return ! isset(self::FEATURES[$pref]) || Features::enabled(self::FEATURES[$pref]);
    }

    /** @return list<string> stored type names whose feature is off (hidden from every list and count) */
    public static function hiddenTypes(): array
    {
        $types = array_merge(array_keys(self::FEATURES), array_keys(self::PREF));

        return array_values(array_filter($types, fn (string $t) => ! self::visible($t)));
    }

    /** The link, or null when it points at a page whose feature is off. */
    public static function link(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        foreach (self::URL_FEATURES as $prefix => $feature) {
            if (($path === $prefix || str_starts_with($path, $prefix.'/')) && Features::disabled($feature)) {
                return null;
            }
        }

        return $url;
    }

    /** Kinds of news that can also go to the phone. */
    public const PUSH = ['earning', 'order', 'bonus', 'cashout', 'signal'];

    /** Older type names that share a setting. */
    private const PREF = ['badge_unlocked' => 'badge'];

    public static function add(User $user, string $type, string $title, ?string $body = null, ?string $url = null, ?string $key = null): void
    {
        $pref = self::PREF[$type] ?? $type;
        if (Features::disabled('account.notifications') || Features::disabled(self::FEATURES[$pref] ?? 'account.notifications')
            || ! NotifyPrefs::on($user, 'bell.'.$pref)) {
            return;
        }
        // "Ignore" = the same event (same key) is never shown twice, even on a retry.
        $added = UserNotification::query()->insertOrIgnore([
            'user_id' => $user->id, 'type' => $type, 'title' => mb_substr($title, 0, 120),
            'body' => mb_substr((string) $body, 0, 500), 'url' => $url,
            'dedupe_key' => $key, 'created_at' => now(),
        ]);
        // Phone notification (if the person turned it on for this kind of news).
        if ($added > 0 && in_array($type, self::PUSH, true) && NotifyPrefs::on($user, "push.$type")
            && Features::enabled('account.push') && WebPush::configured()) {
            SendWebPush::dispatch($user->id)->afterCommit();
        }
    }

    /** Safe words for the phone's lock screen: never an amount or a name. */
    public static function lockScreenTitle(string $type): string
    {
        return match ($type) {
            'earning' => 'New team earnings', 'order' => 'Order update', 'bonus' => 'Badge bonus', 'cashout' => 'Cash-out update',
            'team' => 'Your team grew', 'goal' => 'Goal reached', 'signal' => 'New trading signal', default => 'You have news',
        };
    }

    public static function unread(User $user): int
    {
        if (Features::disabled('account.notifications')) {
            return 0;
        }

        return UserNotification::query()->shown()->where('user_id', $user->id)->whereNull('read_at')->limit(100)->count();
    }

    public static function markAllRead(User $user): void
    {
        UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Daily: read lines go after 90 days, everything after a year. */
    public static function prune(): int
    {
        return UserNotification::query()->where(fn ($q) => $q->where('read_at', '<', now()->subDays(90))
            ->orWhere('created_at', '<', now()->subYear()))->delete();
    }
}
