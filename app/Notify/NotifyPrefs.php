<?php

namespace App\Notify;

use App\Models\User;

/**
 * What each person wants to hear about. Stored on the user as small JSON;
 * anything not set uses the default below.
 */
final class NotifyPrefs
{
    /** @var array<string, bool> */
    public const DEFAULTS = [
        'bell.earning' => true, 'bell.team' => true, 'bell.badge' => true, 'bell.bonus' => true,
        'bell.order' => true, 'bell.renewal' => true, 'bell.cashout' => true, 'bell.goal' => true,
        'bell.signal' => false,
        'cart_reminder' => true, 'offers' => true,
        'push.earning' => false, 'push.order' => false, 'push.bonus' => false, 'push.cashout' => false, 'push.signal' => false,
    ];

    public static function on(User $user, string $key): bool
    {
        $prefs = is_array($user->notify_prefs) ? $user->notify_prefs : [];

        return (bool) ($prefs[$key] ?? self::DEFAULTS[$key] ?? true);
    }

    /** @param array<string, bool> $values only known keys are kept */
    public static function set(User $user, array $values): void
    {
        $prefs = is_array($user->notify_prefs) ? $user->notify_prefs : [];
        foreach ($values as $key => $on) {
            if (array_key_exists($key, self::DEFAULTS)) {
                $prefs[$key] = (bool) $on;
            }
        }
        $user->forceFill(['notify_prefs' => $prefs])->save();
    }

    /** @return list<string> pairs this person wants signal notes for (empty = all) */
    public static function pairs(User $user): array
    {
        $p = is_array($user->notify_prefs) ? ($user->notify_prefs['signal_pairs'] ?? []) : [];

        return is_array($p) ? array_values(array_filter($p, 'is_string')) : [];
    }

    /** @param list<string> $pairs */
    public static function setPairs(User $user, array $pairs): void
    {
        $prefs = is_array($user->notify_prefs) ? $user->notify_prefs : [];
        $prefs['signal_pairs'] = array_values(array_unique(array_slice($pairs, 0, 50)));
        $user->forceFill(['notify_prefs' => $prefs])->save();
    }
}
