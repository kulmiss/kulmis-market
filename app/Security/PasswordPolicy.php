<?php

namespace App\Security;

use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * One password rule for every place a password is set (sign-up, change,
 * reset, admin accounts): long enough, not on the common-password list, not
 * built from the person's own phone/email/name, and (optionally) not in a
 * known breach. No "must contain a symbol" rules: length beats complexity.
 */
class PasswordPolicy
{
    /** @var array<string, true>|null */
    private static ?array $common = null;

    /**
     * @param  array{phone?: ?string, email?: ?string, first_name?: ?string, last_name?: ?string}  $person
     * @return list<mixed>
     */
    public static function rules(bool $staff = false, array $person = []): array
    {
        $min = max(1, (int) config($staff ? 'security.staff_password_min' : 'security.customer_password_min'));
        $rule = Password::min($min)->max((int) config('security.password_max'));
        if (config('security.pwned_check')) {
            $rule = $rule->uncompromised();
        }

        return ['required', 'string', $rule, self::notCommon(), self::notPersonal($person)];
    }

    public static function minLength(bool $staff = false): int
    {
        return max(1, (int) config($staff ? 'security.staff_password_min' : 'security.customer_password_min'));
    }

    public static function isCommon(string $password): bool
    {
        self::$common ??= self::load();
        $p = mb_strtolower(trim($password));

        if (preg_match('/^\d+$/', $p) && strlen($p) < 16) {
            return true; // numbers only (phone numbers, dates, 1234…) are guessed first
        }
        if (preg_match('/^(.)\1+$/u', $p)) {
            return true; // aaaaaaaaaa
        }

        return isset(self::$common[$p])
            // "password1!", "Password2024" — a common word with digits/symbols tacked on.
            || isset(self::$common[rtrim($p, '0123456789!@#$%^&*.?_- ')]);
    }

    private static function notCommon(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && self::isCommon($value)) {
                $fail('This password is too common. Pick something harder to guess — a short sentence works well.');
            }
        };
    }

    /** @param array{phone?: ?string, email?: ?string, first_name?: ?string, last_name?: ?string} $person */
    private static function notPersonal(array $person): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($person) {
            if (! is_string($value)) {
                return;
            }
            $v = mb_strtolower($value);
            $digits = preg_replace('/\D+/', '', $value) ?? '';
            $phone = preg_replace('/\D+/', '', (string) ($person['phone'] ?? '')) ?? '';
            $local = mb_strtolower(strstr((string) ($person['email'] ?? ''), '@', true) ?: '');
            $hit = ($phone !== '' && strlen($digits) >= 6 && str_contains($phone, substr($digits, -9)))
                || ($local !== '' && mb_strlen($local) >= 4 && str_contains($v, $local))
                || collect([$person['first_name'] ?? null, $person['last_name'] ?? null])
                    ->filter(fn ($n) => is_string($n) && mb_strlen($n) >= 3)
                    ->contains(fn ($n) => preg_replace('/[^a-z]/', '', $v) === mb_strtolower($n) || $v === mb_strtolower($n).preg_replace('/\D/', '', $v));
            if ($hit) {
                $fail("Don't use your phone number, email or name as your password.");
            }
        };
    }

    /** @return array<string, true> */
    private static function load(): array
    {
        $list = [];
        $file = resource_path('security/common-passwords.txt');
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line[0] !== '#') {
                $list[$line] = true;
            }
        }

        return $list;
    }
}
