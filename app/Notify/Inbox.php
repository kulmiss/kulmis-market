<?php

namespace App\Notify;

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
        'cashout' => 'My cash-outs', 'goal' => 'My monthly goal',
    ];

    /** Older type names that share a setting. */
    private const PREF = ['badge_unlocked' => 'badge'];

    public static function add(User $user, string $type, string $title, ?string $body = null, ?string $url = null, ?string $key = null): void
    {
        if (Features::disabled('account.notifications') || ! NotifyPrefs::on($user, 'bell.'.(self::PREF[$type] ?? $type))) {
            return;
        }
        // "Ignore" = the same event (same key) is never shown twice, even on a retry.
        UserNotification::query()->insertOrIgnore([
            'user_id' => $user->id, 'type' => $type, 'title' => mb_substr($title, 0, 120),
            'body' => mb_substr((string) $body, 0, 500), 'url' => $url,
            'dedupe_key' => $key, 'created_at' => now(),
        ]);
    }

    public static function unread(User $user): int
    {
        if (Features::disabled('account.notifications')) {
            return 0;
        }

        return UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->limit(100)->count();
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
