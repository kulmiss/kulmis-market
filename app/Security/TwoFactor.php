<?php

namespace App\Security;

use App\Models\User;
use App\Support\Activity;
use App\Support\Totp;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Two-step sign-in with an authenticator app (TOTP, RFC 6238). Required for
 * staff, optional for customers. Recovery codes work once each.
 */
class TwoFactor
{
    public function __construct(private readonly SecurityAlerts $alerts) {}

    /** @return array{secret: string, otpauth_uri: string, qr_svg: string} */
    public function begin(User $user): array
    {
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['two_factor' => 'Two-step sign-in is already on.']);
        }
        $secret = Totp::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();
        $uri = Totp::uri($secret, $user->email ?? (string) $user->phone, (string) config('app.name'));

        return ['secret' => $secret, 'otpauth_uri' => $uri,
            'qr_svg' => (new QRCode(new QROptions(['outputType' => 'svg', 'outputBase64' => false, 'addQuietzone' => true])))->render($uri)];
    }

    /** @return list<string> one-time recovery codes (shown once) */
    public function confirm(User $user, string $code): array
    {
        if ($user->hasTwoFactor() || ! $user->two_factor_secret) {
            throw ValidationException::withMessages(['code' => 'Start the setup again.']);
        }
        $step = Totp::verify((string) $user->two_factor_secret, $code);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Check the time on your phone and try the newest code.']);
        }
        $codes = self::newRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step, 'two_factor_recovery_codes' => $codes])->save();
        $this->alerts->notify($user, 'two_factor_on');

        return $codes;
    }

    /** Turning it off needs the password AND a current code (or a recovery code). Staff are asked to set it up again straight away. */
    public function disable(User $user, string $password, string $code): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Wrong password.']);
        }
        if (! $this->check($user, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not right.']);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
        $this->alerts->notify($user, 'two_factor_off');
    }

    /** A current TOTP code (never the same one twice) or an unused recovery code. */
    public function check(User $user, string $code): bool
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^\d{6}$/', $code)) {
            $step = Totp::verify((string) $user->two_factor_secret, $code, $user->two_factor_last_step);
            if ($step === null) {
                return false;
            }
            $user->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }
        $codes = $user->two_factor_recovery_codes ?? [];
        $i = array_search($code, $codes, true);
        if ($i === false) {
            return false;
        }
        unset($codes[$i]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
        Activity::log('security.recovery_code_used', $user, ['remaining' => count($codes)], null, $user->id);

        return true;
    }

    /** @return list<string> */
    public static function newRecoveryCodes(): array
    {
        return array_map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)), range(1, 8));
    }
}
