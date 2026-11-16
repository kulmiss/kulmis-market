<?php

namespace App\Support;

use App\Models\Badge;
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

    public const BADGE_PREFIX = 'badge.';

    /** @var array<string, array{name:string, active:bool}>|null */
    private static ?array $badges = null;

    public static function enabled(string $key): bool
    {
        if (str_starts_with($key, self::BADGE_PREFIX)) {
            return self::own($key) && self::enabled('badges');
        }
        $def = self::definition($key);
        if (! self::own($key)) {
            return false;
        }

        return $def[3] === null || self::enabled($def[3]);
    }

    /**
     * A member benefit (member price, free delivery, early access, members-only
     * product) for someone who is an active member right now. While membership
     * is switched off, people who already paid keep every benefit until their
     * paid month ends, so the membership switch itself is skipped here; the
     * benefit's own switch (and its other parents) still count.
     */
    public static function memberBenefit(string $key): bool
    {
        if (self::enabled($key)) {
            return true;
        }
        if (self::enabled('membership')) {
            return false;
        }
        for ($k = $key; $k !== null && $k !== 'membership'; $k = self::definition($k)[3]) {
            if (! self::own($k)) {
                return false;
            }
        }

        return true;
    }

    public static function disabled(string $key): bool
    {
        return ! self::enabled($key);
    }

    /** The switch itself, ignoring parents. */
    public static function own(string $key): bool
    {
        if (str_starts_with($key, self::BADGE_PREFIX)) {
            return self::badges()[substr($key, strlen(self::BADGE_PREFIX))]['active']
                ?? throw new InvalidArgumentException("Unknown feature [$key].");
        }
        $def = self::definition($key);
        $overrides = self::overrides();

        return $overrides[$key] ?? (bool) $def[2];
    }

    public static function set(string $key, bool $enabled, ?int $actorId = null): void
    {
        $before = self::own($key); // also throws for unknown keys
        if (str_starts_with($key, self::BADGE_PREFIX)) {
            // A badge's switch is its own "active" flag (also on the Badges page).
            Badge::query()->where('key', substr($key, strlen(self::BADGE_PREFIX)))->update(['is_active' => $enabled]);
        } else {
            FeatureFlag::query()->updateOrCreate(['key' => $key], ['enabled' => $enabled, 'updated_by' => $actorId]);
        }
        self::flush();
        PageCache::clear();
        if ($before !== $enabled) {
            Activity::log('feature.'.($enabled ? 'enabled' : 'disabled'), null, ['key' => $key]);
        }
    }

    /**
     * Overview for the "what's on/off" page.
     *
     * @return list<array{key:string,label:string,group:string,description:string,enabled:bool,effective:bool,parent:?string}>
     */
    public static function overview(): array
    {
        $rows = [];
        foreach (config('features') as $key => $def) {
            $rows[] = [
                'key' => $key,
                'label' => $def[0],
                'group' => $def[1],
                'description' => (string) ($def[4] ?? ''),
                'enabled' => self::own($key),
                'effective' => self::enabled($key),
                'parent' => $def[3],
            ];
            if ($key === 'badges.messages') {
                foreach (self::badges() as $badgeKey => $badge) {
                    $rows[] = [
                        'key' => self::BADGE_PREFIX.$badgeKey,
                        'label' => $badge['name'],
                        'group' => 'Badges',
                        'description' => $badge['description'],
                        'enabled' => $badge['active'],
                        'effective' => self::enabled(self::BADGE_PREFIX.$badgeKey),
                        'parent' => 'badges',
                    ];
                }
            }
        }

        return $rows;
    }

    public static function exists(string $key): bool
    {
        if (str_starts_with($key, self::BADGE_PREFIX)) {
            return isset(self::badges()[substr($key, strlen(self::BADGE_PREFIX))]);
        }

        return array_key_exists($key, config('features'));
    }

    /** Changes whenever any switch changes: for caching things that depend on the switches. */
    public static function fingerprint(): string
    {
        $o = self::overrides();
        ksort($o);

        return md5(json_encode($o).'|'.json_encode(array_map(fn ($b) => $b['active'], self::badges())));
    }

    /**
     * Forget only this process's copy (queue workers live long: each job
     * must see switches the Super Admin changed since the worker started).
     */
    public static function forgetMemo(): void
    {
        self::$overrides = null;
        self::$badges = null;
    }

    public static function flush(): void
    {
        self::$overrides = null;
        self::$badges = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, array{name:string, description:string, active:bool}> */
    private static function badges(): array
    {
        return self::$badges ??= Badge::query()->orderBy('rank')->orderBy('id')
            ->get(['key', 'name', 'description', 'is_active'])
            ->mapWithKeys(fn (Badge $b) => [$b->key => ['name' => 'Badge: '.$b->name, 'description' => (string) $b->description, 'active' => (bool) $b->is_active]])
            ->all();
    }

    /** @return array{0:string,1:string,2:bool,3:?string,4?:string} */
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
