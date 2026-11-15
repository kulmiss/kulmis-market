<?php

namespace App\Support;

use App\Models\FeatureFlag;
use App\Storefront\PageCache;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Feature switches. Defaults come from config/features.php; admin overrides
 * live in the feature_flags table. A feature is effectively on only when it
 * and every parent are on.
 */
class Features
{
    private const CACHE_KEY = 'features.overrides';

    /** @var array<string, bool>|null */
    private static ?array $overrides = null;

    public static function enabled(string $key): bool
    {
        $def = self::definition($key);
        if (! self::own($key)) {
            return false;
        }

        return $def[3] === null || self::enabled($def[3]);
    }

    public static function disabled(string $key): bool
    {
        return ! self::enabled($key);
    }

    /** The switch itself, ignoring parents. */
    public static function own(string $key): bool
    {
        $def = self::definition($key);
        $overrides = self::overrides();

        return $overrides[$key] ?? (bool) $def[2];
    }

    public static function set(string $key, bool $enabled, ?int $actorId = null): void
    {
        self::definition($key);
        $before = self::own($key);
        FeatureFlag::query()->updateOrCreate(['key' => $key], ['enabled' => $enabled, 'updated_by' => $actorId]);
        self::flush();
        PageCache::clear();
        if ($before !== $enabled) {
            Activity::log('feature.'.($enabled ? 'enabled' : 'disabled'), null, ['key' => $key]);
        }
    }

    /**
     * Overview for the "what's on/off" page.
     *
     * @return list<array{key:string,label:string,group:string,enabled:bool,effective:bool,parent:?string}>
     */
    public static function overview(): array
    {
        $rows = [];
        foreach (config('features') as $key => $def) {
            $rows[] = [
                'key' => $key,
                'label' => $def[0],
                'group' => $def[1],
                'enabled' => self::own($key),
                'effective' => self::enabled($key),
                'parent' => $def[3],
            ];
        }

        return $rows;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, config('features'));
    }

    public static function flush(): void
    {
        self::$overrides = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array{0:string,1:string,2:bool,3:?string} */
    private static function definition(string $key): array
    {
        $def = config('features')[$key] ?? null;
        if (! is_array($def)) {
            throw new InvalidArgumentException("Unknown feature [$key].");
        }

        return $def;
    }

    /** @return array<string, bool> */
    private static function overrides(): array
    {
        return self::$overrides ??= Cache::rememberForever(self::CACHE_KEY, fn () => FeatureFlag::query()
            ->pluck('enabled', 'key')->map(fn ($v) => (bool) $v)->all());
    }
}
