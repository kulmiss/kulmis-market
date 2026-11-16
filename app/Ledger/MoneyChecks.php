<?php

namespace App\Ledger;

use App\Badges\ProfitGuard;
use App\Models\Commission;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * "The shop never collapses" rules, checked after every money test and
 * shown on the Money health page:
 *  1. The books add up (every entry balances).
 *  2. Members only earn from money that was really paid in: every team
 *     earning belongs to a payment that was paid (or later refunded, then
 *     taken back), never to an unpaid one.
 *  3. From one payment, members never get more than was paid, and never
 *     more than the guard limit (35%) of what stayed paid after refunds.
 *  4. Altogether, members got at most 35% of all the money paid in.
 */
final class MoneyChecks
{
    /** @return list<string> what is wrong (empty = all good) */
    public static function problems(): array
    {
        $out = [];
        if (! LedgerCheck::balanced()) {
            $out[] = 'The books do not add up (an entry does not balance).';
        }
        $max = ProfitGuard::MAX_BPS;

        $unpaid = Commission::query()->where('status', '!=', Commission::SKIPPED)->where('amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('payment_id')->orWhereNotIn('payment_id', Payment::query()->withoutGlobalScopes()
                ->whereIn('status', [Payment::PAID, 'partially_refunded', 'refunded'])->select('id')))->count();
        if ($unpaid > 0) {
            $out[] = "$unpaid team earnings come from a payment that was never paid.";
        }

        // Per payment: team earnings + first-payment bonus (+ welcome-back reward) on it.
        $earned = DB::table('commissions')->whereNotNull('payment_id')->where('status', '!=', Commission::SKIPPED)
            ->groupBy('payment_id')->selectRaw('payment_id, SUM(amount - reversed_amount) AS net')->pluck('net', 'payment_id');
        $rewards = DB::table('rewards')->whereIn('kind', ['first_payment', 'comeback'])->whereNotNull('source_id')
            ->groupBy('source_type', 'source_id')->selectRaw('source_type, source_id, SUM(amount - reversed_amount) AS net')->get();
        $bySource = [];
        foreach ($rewards as $r) {
            $bySource[$r->source_type.':'.$r->source_id] = (int) $r->net;
        }
        // Per thing bought (an order may be paid in parts: gift card, wallet, transfer).
        $payments = Payment::query()->withoutGlobalScopes()->whereIn('status', [Payment::PAID, 'partially_refunded', 'refunded'])
            ->get(['id', 'provider', 'payable_type', 'payable_id', 'amount', 'refunded_amount']);
        $in = 0;
        $kept = [];
        $net = [];
        foreach ($payments as $p) {
            $key = $p->payable_type.':'.$p->payable_id;
            $kept[$key] = ($kept[$key] ?? 0) + $p->amount - $p->refunded_amount;
            $net[$key] = ($net[$key] ?? $bySource[$key] ?? 0) + (int) ($earned[$p->id] ?? 0);
            if (! in_array($p->provider, [Payment::WALLET, Payment::GIFT_CARD], true)) {
                $in += $p->amount - $p->refunded_amount; // new money only (wallet and gift-card money came in before)
            }
        }
        $bad = 0;
        foreach ($net as $key => $n) {
            if ($n > $kept[$key] || $n * 10_000 > $kept[$key] * $max) {
                $bad++;
            }
        }
        if ($bad > 0) {
            $out[] = "$bad purchases paid members more than ".($max / 100).'% of the money that stayed paid.';
        }
        $all = (int) DB::table('commissions')->where('status', '!=', Commission::SKIPPED)->sum(DB::raw('amount - reversed_amount'))
            + (int) DB::table('rewards')->sum(DB::raw('amount - reversed_amount'))
            + (int) DB::table('badge_bonuses')->sum(DB::raw('amount - reduced_amount'));
        if ($in > 0 && $all * 10_000 > $in * $max) {
            $out[] = 'Members got more than '.($max / 100).'% of all the money paid in.';
        }

        return $out;
    }
}
