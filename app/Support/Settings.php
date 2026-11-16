<?php

namespace App\Support;

use App\Models\Setting;
use App\Storefront\PageCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Typed, admin-editable settings. Defaults and types: config/settings.php.
 * Money is integer santim, percentages are basis points.
 */
class Settings
{
    private const CACHE_KEY = 'settings.overrides';

    /** @var array<string, mixed>|null */
    private static ?array $overrides = null;

    public static function get(string $key): mixed
    {
        $def = self::definition($key);
        $overrides = self::overrides();

        return array_key_exists($key, $overrides) ? self::cast($def[0], $overrides[$key]) : $def[1];
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function string(string $key): string
    {
        return (string) self::get($key);
    }

    /** An editable message text with its {if feature} … {/if} parts applied (parts of switched-off features removed). */
    public static function template(string $key): string
    {
        return FeatureText::apply(self::string($key));
    }

    public static function set(string $key, mixed $value, ?int $actorId = null, ?string $reason = null): void
    {
        $def = self::definition($key);
        $value = self::validate($key, $def[0], $value);
        $before = self::get($key);
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $actorId]);
        self::flush();
        PageCache::clear();
        if ($before !== $value) {
            Activity::log('setting.updated', null, ['key' => $key, 'from' => $before, 'to' => $value], $reason);
        }
    }

    /** @return list<array{key:string,type:string,label:string,group:string,value:mixed,default:mixed,options?:list<string>}> */
    public static function all(): array
    {
        $rows = [];
        foreach (config('settings') as $key => $def) {
            $rows[] = ['key' => $key, 'type' => $def[0], 'label' => $def[2], 'group' => $def[3],
                'value' => self::get($key), 'default' => $def[1]] + (isset($def[4]) ? ['options' => $def[4]] : []);
        }

        return $rows;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, config('settings'));
    }

    /** Forget only this process's copy (see Features::forgetMemo). */
    public static function forgetMemo(): void
    {
        self::$overrides = null;
    }

    public static function flush(): void
    {
        self::$overrides = null;
        Cache::forget(self::CACHE_KEY);
        Currency::flush();
    }

    private static function validate(string $key, string $type, mixed $value): mixed
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages([$key => $msg]);

        return match ($type) {
            'money', 'int', 'ratio' => (is_int($value) || (is_string($value) && ctype_digit($value)))
                && (int) $value >= 0 ? (int) $value : $fail('Must be a whole number ≥ 0.'),
            'bps' => (is_int($value) || (is_string($value) && ctype_digit($value)))
                && (int) $value >= 0 && (int) $value <= 10_000 ? (int) $value : $fail('Must be between 0 and 10000 (0–100%).'),
            'bool' => is_bool($value) ? $value : (in_array($value, [0, 1, '0', '1'], true) ? (bool) $value : $fail('Must be true or false.')),
            'string' => is_string($value) && mb_strlen($value) <= 255 ? trim($value) : $fail('Must be text up to 255 characters.'),
            'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fail('Must be a colour like #1f8a4c.'),
            'currency_code' => is_string($value) && preg_match('/^[A-Za-z]{3}$/', trim($value)) ? strtoupper(trim($value)) : $fail('Must be a 3-letter currency code, like ETB or USD.'),
            'choice' => is_string($value) && in_array($value, self::definition($key)[4] ?? [], true) ? $value : $fail('Pick one of: '.implode(', ', self::definition($key)[4] ?? []).'.'),
            'text' => is_string($value) && mb_strlen($value) <= 20_000 ? $value : $fail('Must be text up to 20000 characters.'),
            default => throw new InvalidArgumentException("Unknown setting type [$type]."),
        };
    }

    private static function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'money', 'int', 'ratio', 'bps' => (int) $value,
            'bool' => (bool) $value,
            default => (string) $value,
        };
    }

    /** @return array{0:string,1:mixed,2:string,3:string,4?:list<string>} */
    private static function definition(string $key): array
    {
        $def = config('settings')[$key] ?? null;
        if (! is_array($def)) {
            throw new InvalidArgumentException("Unknown setting [$key].");
        }

        return $def;
    }

    /** @return array<string, mixed> */
    private static function overrides(): array
    {
        if (self::$overrides !== null) {
            return self::$overrides;
        }
        try {
            return self::$overrides = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());
        } catch (QueryException) {
            return []; // before the first migration (install, health checks): defaults only, not cached
        }
    }
}
