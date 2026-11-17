<?php

namespace App\Home;

use App\Demo\Demo;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Product;
use App\Signals\SignalStats;
use App\Support\Features;
use Illuminate\Support\Facades\Cache;

/**
 * "Trust numbers" on the home page: counted from the real data, never typed
 * in. Demo rows count only while "Show demo data" is on (like everywhere).
 * A number that is 0, or whose feature is off, is not shown.
 */
final class HomeNumbers
{
    public const LABELS = [
        'products' => 'Handmade pieces in the shop',
        'customers' => 'Happy customers',
        'orders' => 'Orders delivered',
        'cities' => 'Towns we delivered to',
        'members' => 'Members',
        'signal_win_rate' => 'Signals that won (closed signals)',
    ];

    /** @var array<string, list<string>> */
    private const NEEDS = [
        'products' => ['shop'], 'customers' => ['shop'], 'orders' => ['shop'], 'cities' => ['shop'],
        'members' => ['membership'], 'signal_win_rate' => ['signals', 'signals.performance_page'],
    ];

    /** @return array<string, string> key => shown value (only numbers above 0 with their features on) */
    public static function all(): array
    {
        $raw = Cache::remember('home.numbers'.(Demo::visible() ? ':demo' : ''), 600, fn () => self::count());
        $out = [];
        foreach ($raw as $k => $v) {
            if ($v > 0 && collect(self::NEEDS[$k])->every(fn ($f) => Features::enabled($f))) {
                $out[$k] = $k === 'signal_win_rate' ? rtrim(rtrim(number_format($v, 1), '0'), '.').'%' : number_format((int) $v);
            }
        }

        return $out;
    }

    public static function forget(): void
    {
        Cache::forget('home.numbers');
        Cache::forget('home.numbers:demo');
    }

    /** @return array<string, int|float> */
    private static function count(): array
    {
        $delivered = Order::query()->where('status', Order::DELIVERED);
        $stats = app(SignalStats::class)->totals();

        return [
            'products' => Product::query()->where('status', 'active')->count(),
            'customers' => (clone $delivered)->distinct()->count('phone'),
            'orders' => (clone $delivered)->count(),
            // "Addis Ababa" and "addis ababa " are one town.
            'cities' => (int) (clone $delivered)->selectRaw('COUNT(DISTINCT LOWER(TRIM(city))) as c')->value('c'),
            'members' => Membership::query()->whereIn('status', ['active', 'grace'])->where('current_period_end', '>=', now())->count(),
            // Only once there are enough closed signals for the number to mean something.
            'signal_win_rate' => $stats['closed'] >= 10 ? (float) $stats['win_rate'] : 0,
        ];
    }
}
