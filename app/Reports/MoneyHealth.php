<?php

namespace App\Reports;

use App\Ledger\MoneyChecks;
use App\Models\Payment;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Money health page: is the shop safe? In plain numbers, real data or
 * the demo data (never both).
 *
 * - Money in: new money paid in (transfers, Chapa…) after refunds. Wallet and
 *   gift-card payments are not new money, so they don't count.
 * - Paid to members: team earnings, bonuses, rewards and prizes made in the
 *   period (after anything taken back).
 * - Owed: what members will get or can take now (on the way + ready + cash-outs
 *   waiting), against the money the shop has received and not paid out.
 * - Light: red when members got more than the guard allows (35%), when the
 *   shop owes more than it has, or when a money rule is broken; yellow when
 *   members got more than 25% or a cash-out waits too long; else green.
 */
final class MoneyHealth
{
    public const RED_BELOW = 65.0;

    public const YELLOW_BELOW = 75.0;

    /** @return array<string, mixed> money in santim */
    public function summary(Carbon $from, Carbon $to, bool $demo = false): array
    {
        $newMoney = fn () => DB::table('payments')->where('is_demo', $demo)->whereIn('status', [Payment::PAID, 'partially_refunded', 'refunded'])
            ->whereNotIn('provider', [Payment::WALLET, Payment::GIFT_CARD]);
        $in = (int) $newMoney()->whereBetween('paid_at', [$from, $to])->sum(DB::raw('amount - refunded_amount'));

        $parts = [
            'team_earnings' => (int) DB::table('commissions')->where('is_demo', $demo)->where('status', '!=', 'skipped')
                ->whereBetween('created_at', [$from, $to])->sum(DB::raw('amount - reversed_amount')),
            'badge_bonus' => (int) DB::table('badge_bonuses')->where('is_demo', $demo)->whereBetween('created_at', [$from, $to])->sum(DB::raw('amount - reduced_amount')),
            'rewards_and_prizes' => (int) DB::table('rewards')->where('is_demo', $demo)->whereBetween('created_at', [$from, $to])->sum(DB::raw('amount - reversed_amount')),
            'loyalty' => (int) DB::table('loyalty_rewards')->where('is_demo', $demo)->whereBetween('created_at', [$from, $to])->sum('amount'),
        ];
        $out = array_sum($parts);

        // What members are owed right now, from the books (wallet buckets are liabilities: credit = negative).
        $bucket = fn (string $b) => -(int) DB::table('ledger_postings')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_postings.ledger_account_id')
            ->where('ledger_postings.is_demo', $demo)->where('ledger_accounts.code', 'like', 'user:%:'.$b)->sum('ledger_postings.amount');
        $onTheWay = $bucket('pending');
        $ready = $bucket('available');
        $asked = $bucket('payout');
        $waiting = DB::table('payout_requests')->where('is_demo', $demo)->whereIn('status', ['requested', 'on_hold']);
        $waitingCount = (clone $waiting)->count();
        $oldest = (clone $waiting)->min('created_at');
        $cashOutsPaid = (int) DB::table('payout_requests')->where('is_demo', $demo)->where('status', 'paid')->sum('amount');
        $received = (int) $newMoney()->sum(DB::raw('amount - refunded_amount'));
        $has = $received - $cashOutsPaid;
        $owed = $onTheWay + $ready + $asked;

        $kept = $in - $out;
        $keptPct = $in > 0 ? round(100 * $kept / $in, 1) : 100.0;
        $problems = $demo ? [] : MoneyChecks::problems();
        $late = $oldest !== null && Carbon::parse($oldest)->lt(now()->subDays(max(1, Settings::int('wallet.payout_days'))));
        $why = [];
        if ($keptPct < self::RED_BELOW) {
            $why[] = 'Members got more than 35% of the money that came in.';
        }
        if ($owed > $has) {
            $why[] = 'Members are owed more than the shop has received.';
        }
        foreach ($problems as $p) {
            $why[] = $p;
        }
        $light = $why !== [] ? 'red' : ($keptPct < self::YELLOW_BELOW || $late ? 'yellow' : 'green');
        if ($light === 'yellow') {
            $why[] = $late ? 'A cash-out has waited more than '.Settings::int('wallet.payout_days').' days.' : 'Members got more than 25% of the money that came in.';
        }

        return [
            'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
            'money_in' => $in, 'paid_to_members' => $out, 'parts' => $parts, 'kept' => $kept, 'kept_percent' => $keptPct,
            'on_the_way' => $onTheWay, 'ready_to_use' => $ready, 'cash_outs_waiting' => $asked, 'cash_outs_waiting_count' => $waitingCount,
            'owed' => $owed, 'shop_has' => $has, 'light' => $light, 'why' => $why,
        ];
    }

    /** @return list<array{month:string, money_in:int, paid_to_members:int, kept_percent:float}> the last $n months */
    public function months(int $n = 6, bool $demo = false): array
    {
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $start = now()->startOfMonth()->subMonthsNoOverflow($i);
            $s = $this->summary($start, $start->copy()->endOfMonth(), $demo);
            $out[] = ['month' => $start->format('M Y'), 'money_in' => $s['money_in'], 'paid_to_members' => $s['paid_to_members'], 'kept_percent' => $s['kept_percent']];
        }

        return $out;
    }
}
