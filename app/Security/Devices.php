<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Remembers browsers a customer signed in from, so a sign-in from a new one
 * triggers "Was this you?". A random cookie set only after sign-in; we store
 * its hash and a short "Chrome on Android" label. No IP, no fingerprinting.
 */
class Devices
{
    public function __construct(private readonly SecurityAlerts $alerts) {}

    public function signedIn(User $user, Request $request, string $channel): void
    {
        $name = (string) config('security.device_cookie');
        $id = (string) $request->cookie($name);
        if (strlen($id) !== 40) {
            $id = Str::random(40);
        }
        Cookie::queue(cookie($name, $id, 60 * 24 * 365, '/', null, null, true, false, 'lax'));
        $hash = hash_hmac('sha256', $id, (string) config('app.key'));
        $label = self::label((string) $request->userAgent());
        $known = DB::table('user_devices')->where('user_id', $user->id)->where('device_hash', $hash)->exists();
        if ($known) {
            DB::table('user_devices')->where('user_id', $user->id)->where('device_hash', $hash)->update(['last_seen_at' => now(), 'updated_at' => now()]);

            return;
        }
        $first = ! DB::table('user_devices')->where('user_id', $user->id)->exists();
        DB::table('user_devices')->insert(['user_id' => $user->id, 'device_hash' => $hash, 'label' => $label,
            'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        if (! $first) { // the very first sign-in (right after sign-up) is expected
            $this->alerts->notify($user, 'new_sign_in', ['device' => $label, 'channel' => $channel]);
        }
        if ($user->isStaff() && ! $first) {
            $this->alerts->critical("Admin sign-in from a new browser: {$user->name} ({$label})", ['user' => $user->id]);
        }
    }

    /** New app sign-in (API token): always worth a notice. */
    public function newToken(User $user, string $deviceName): void
    {
        $this->alerts->notify($user, 'new_sign_in', ['device' => mb_substr($deviceName, 0, 60).' (app)', 'channel' => 'api']);
    }

    public static function label(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet', str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari', default => 'A browser',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone/iPad',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux',
            default => 'an unknown device',
        };

        return "$browser on $os";
    }
}
