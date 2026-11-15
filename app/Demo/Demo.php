<?php

namespace App\Demo;

use App\Models\User;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Demo data: every demo row has is_demo = true.
 *
 * - "Show demo data" off (Settings: demo.show) hides demo rows everywhere
 *   through a global scope (HidesDemo) and explicit filters in reports.
 * - Money totals never mix demo and real rows: reports work on one data
 *   set at a time (Demo::dataset()).
 * - Demo::purge() deletes all demo rows (Super Admin, with confirmation).
 */
final class Demo
{
    /** Tables with an is_demo column (parents first is not required: purge orders deletes itself). */
    public const TABLES = [
        'users', 'memberships', 'membership_purchases', 'loyalty_rewards', 'categories', 'products', 'coupons',
        'shipping_zones', 'couriers', 'orders', 'payments', 'refunds', 'commissions', 'payout_accounts', 'payout_requests',
        'fraud_flags', 'signals', 'signal_subscriptions', 'signal_purchases', 'activity_log', 'journal_entries',
        'ledger_postings', 'user_badges', 'user_notifications',
    ];

    private static ?bool $actingDemo = null;

    public static function visible(): bool
    {
        return (bool) Settings::get('demo.show');
    }

    /** Which data set money reports show: demo numbers only while demo data is visible and asked for. */
    public static function dataset(?string $requested = null): string
    {
        if (! self::visible() || ! self::exists()) {
            return 'real';
        }

        return $requested === 'real' ? 'real' : 'demo';
    }

    public static function exists(): bool
    {
        return DB::table('users')->where('is_demo', true)->exists() || DB::table('products')->where('is_demo', true)->exists();
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        $out = [];
        foreach (self::TABLES as $t) {
            $out[$t] = DB::table($t)->where('is_demo', true)->count();
        }

        return $out;
    }

    /** True when a model belongs to demo data. */
    public static function isDemoModel(?Model $model): bool
    {
        return $model !== null && (bool) ($model->getAttribute('is_demo') ?? false);
    }

    public static function isDemoUser(?int $userId): bool
    {
        return $userId !== null && (bool) DB::table('users')->where('id', $userId)->value('is_demo');
    }

    /**
     * A demo customer is signed in: what they create is demo data too.
     * (Demo staff acting on real records do NOT make them demo.)
     */
    public static function actingDemoCustomer(): bool
    {
        if (self::$actingDemo !== null) {
            return self::$actingDemo;
        }
        foreach (['web', 'sanctum'] as $guard) {
            try {
                $user = Auth::guard($guard)->user();
            } catch (\Throwable) {
                $user = null;
            }
            if ($user instanceof User) {
                return (bool) $user->getAttribute('is_demo') && ! $user->isStaff();
            }
        }

        return false;
    }

    /** For the seeder: everything created inside $fn is demo data. */
    public static function capture(callable $fn): mixed
    {
        $before = self::$actingDemo;
        self::$actingDemo = true;
        try {
            return $fn();
        } finally {
            self::$actingDemo = $before;
        }
    }

    public static function resetRequestState(): void
    {
        self::$actingDemo = null;
    }

    /** Contacts the demo data uses: messages to them are never sent. */
    public static function isDemoContact(?string $phone, ?string $email): bool
    {
        return ($phone !== null && str_starts_with($phone, '+251900'))
            || ($email !== null && str_ends_with(mb_strtolower($email), '.test'));
    }

    /**
     * Delete every demo row and demo product photo. Real data is untouched.
     *
     * @return array<string, int> rows deleted per table
     */
    public static function purge(?int $actorId = null): array
    {
        if (! self::exists() && array_sum(self::counts()) === 0) {
            return [];
        }
        $ids = fn (string $t) => DB::table($t)->where('is_demo', true)->pluck('id')->all();
        $users = $ids('users');
        $products = $ids('products');
        $orders = $ids('orders');
        $entries = $ids('journal_entries');
        $before = self::counts();
        $images = DB::table('product_images')->whereIn('product_id', $products)->get();

        DB::transaction(function () use ($users, $products, $orders, $entries) {
            $in = fn (string $table, string $col, array $vals) => $vals === [] ? 0 : DB::table($table)->whereIn($col, $vals)->delete();
            // Children of demo rows.
            $in('ledger_postings', 'journal_entry_id', $entries);
            DB::table('ledger_postings')->where('is_demo', true)->delete();
            DB::table('journal_entries')->where('is_demo', true)->whereNotNull('reverses_entry_id')->delete();
            DB::table('journal_entries')->where('is_demo', true)->delete();
            $accounts = DB::table('ledger_accounts')->where(fn ($q) => collect($users)->each(fn ($u) => $q->orWhere('code', 'like', "user:$u:%")))->pluck('id')->all();
            if ($users !== [] && $accounts !== [] && ! DB::table('ledger_postings')->whereIn('ledger_account_id', $accounts)->exists()) {
                $in('ledger_accounts', 'id', $accounts);
            }
            $in('order_items', 'order_id', $orders);
            $in('order_status_history', 'order_id', $orders);
            $in('coupon_redemptions', 'order_id', $orders);
            foreach (['refunds', 'commissions', 'payments', 'loyalty_rewards', 'user_badges', 'user_notifications', 'payout_requests',
                'payout_accounts', 'fraud_flags', 'signal_purchases', 'signal_subscriptions', 'activity_log', 'orders'] as $t) {
                DB::table($t)->where('is_demo', true)->delete();
            }
            DB::table('membership_reminders')->whereIn('membership_id', DB::table('memberships')->where('is_demo', true)->select('id'))->delete();
            DB::table('membership_purchases')->where('is_demo', true)->delete();
            DB::table('memberships')->where('is_demo', true)->delete();
            DB::table('signals')->where('is_demo', true)->delete();
            $in('cart_items', 'product_id', $products);
            $in('product_images', 'product_id', $products);
            $in('product_variants', 'product_id', $products);
            $in('product_tag', 'product_id', $products);
            DB::table('cart_items')->whereIn('cart_id', DB::table('carts')->whereIn('user_id', $users ?: [0])->select('id'))->delete();
            $in('carts', 'user_id', $users);
            DB::table('products')->where('is_demo', true)->delete();
            DB::table('coupons')->where('is_demo', true)->delete();
            DB::table('categories')->where('is_demo', true)->delete();
            DB::table('shipping_zones')->where('is_demo', true)->delete();
            DB::table('couriers')->where('is_demo', true)->delete();
            foreach (['sessions', 'personal_access_tokens', 'user_devices', 'password_reset_codes'] as $t) {
                $col = $t === 'personal_access_tokens' ? 'tokenable_id' : 'user_id';
                $q = DB::table($t)->whereIn($col, $users ?: [0]);
                if ($t === 'personal_access_tokens') {
                    $q->where('tokenable_type', (new User)->getMorphClass());
                }
                $q->delete();
            }
            // Real people who were referred by a demo member keep their account, without the demo referrer.
            DB::table('users')->where('is_demo', false)->whereIn('referrer_id', $users ?: [0])->update(['referrer_id' => null]);
            DB::table('users')->where('is_demo', true)->update(['referrer_id' => null]);
            DB::table('users')->where('is_demo', true)->delete();
        });

        foreach ($images as $img) {
            foreach ((array) json_decode((string) $img->widths, true) as $w) {
                foreach ((array) json_decode((string) $img->formats, true) as $f) {
                    Storage::disk('public')->delete("{$img->path}-$w.$f");
                }
            }
        }
        Settings::flush();
        Activity::log('demo.deleted', null, ['rows' => array_filter($before)], null, $actorId);

        return array_filter($before);
    }

    /** The seeder must never run on a live shop. */
    public static function assertAllowed(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo data can not be added when APP_ENV=production.');
        }
    }
}
