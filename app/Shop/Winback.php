<?php

namespace App\Shop;

use App\Jobs\SendCustomerMessage;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Notify\Message;
use App\Notify\NotifyPrefs;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "We miss you": once per person, a personal one-use code after N days
 * without an order. Only people who bought before; they can turn offers off.
 */
class Winback
{
    public function run(): int
    {
        if (Features::disabled('shop') || Features::disabled('shop.winback') || Features::disabled('shop.coupons')) {
            return 0;
        }
        $cutoff = now()->subDays(max(14, Settings::int('winback.days')));
        $sent = 0;
        User::query()->whereNull('winback_sent_at')->where('status', 'active')
            ->whereHas('orders', fn ($q) => $q->whereNotNull('paid_at'))
            ->whereDoesntHave('orders', fn ($q) => $q->where('created_at', '>=', $cutoff))
            ->limit(500)->get()->each(function (User $u) use (&$sent) {
                if (! NotifyPrefs::on($u, 'offers')) {
                    return;
                }
                $claimed = DB::table('users')->where('id', $u->id)->whereNull('winback_sent_at')->update(['winback_sent_at' => now()]);
                if ($claimed === 0) {
                    return;
                }
                $bps = max(100, min(5_000, Settings::int('winback.percent_bps')));
                $code = 'BACK'.Str::upper(Str::random(6));
                Coupon::query()->create(['code' => $code, 'type' => Coupon::PERCENT, 'value' => $bps, 'max_uses' => 1, 'max_uses_per_customer' => 1,
                    'ends_at' => now()->addDays(14), 'is_active' => true, 'note' => "Win-back for user #{$u->id}"]);
                $body = strtr(Settings::string('notify.winback'), ['{name}' => $u->first_name, '{site}' => Settings::string('site.name'),
                    '{percent}' => rtrim(rtrim(number_format($bps / 100, 2), '0'), '.').'%', '{code}' => $code,
                    '{until}' => now()->addDays(14)->timezone(config('app.timezone'))->format('d M'), '{url}' => url('/shop')]);
                SendCustomerMessage::dispatch(new Message('A little gift from '.Settings::string('site.name'), $body, $u->phone, $u->email, $u->telegram_user_id, 'winback'));
                $sent++;
            });

        return $sent;
    }
}
