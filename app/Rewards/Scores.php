<?php

namespace App\Rewards;

use App\Models\Commission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Counting for contests and challenges: only real, still-paid money. */
final class Scores
{
    /**
     * New paying members per inviter (level 1): people whose very first real
     * membership payment was in the period and was not refunded.
     *
     * @return array<int, int> inviter id => count, biggest first
     */
    public static function newPayingMembers(Carbon $from, Carbon $to, ?int $onlyInviter = null): array
    {
        return DB::table('membership_purchases as mp')->join('users as u', 'u.id', '=', 'mp.user_id')
            ->whereNotNull('u.referrer_id')->where('u.is_demo', false)->where('mp.status', 'paid')
            ->whereRaw('mp.amount > mp.wallet_used')->where('mp.paid_at', '>=', $from)->where('mp.paid_at', '<', $to)
            ->when($onlyInviter, fn ($q, $id) => $q->where('u.referrer_id', $id))
            ->whereNotExists(fn ($q) => $q->from('membership_purchases as p2')->whereColumn('p2.user_id', 'mp.user_id')
                ->whereColumn('p2.paid_at', '<', 'mp.paid_at')->where('p2.amount', '>', 0))
            ->groupBy('u.referrer_id')->selectRaw('u.referrer_id as uid, COUNT(DISTINCT mp.user_id) as score')
            ->orderByDesc('score')->orderBy('uid')->pluck('score', 'uid')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Shop sales through the member's link or code (level 1), after refunds.
     *
     * @return array<int, int> member id => santim, biggest first
     */
    public static function shopSales(Carbon $from, Carbon $to, ?int $only = null): array
    {
        return Commission::query()->where('type', 'products')->where('level', 1)
            ->whereIn('status', [Commission::PENDING, Commission::AVAILABLE])
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->where('is_demo', false)
            ->when($only, fn ($q, $id) => $q->where('recipient_id', $id))
            ->groupBy('recipient_id')
            ->selectRaw('recipient_id as uid, SUM(base_amount - CASE WHEN amount > 0 THEN base_amount * reversed_amount / amount ELSE 0 END) as score')
            ->orderByDesc('score')->orderBy('uid')->pluck('score', 'uid')->map(fn ($v) => (int) $v)->all();
    }

    /** Real membership money paid in the period (the base for prize budgets). */
    public static function membershipMoney(Carbon $from, Carbon $to): int
    {
        return (int) DB::table('membership_purchases')->where('status', 'paid')->where('is_demo', false)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->sum(DB::raw('amount - wallet_used'));
    }
}
