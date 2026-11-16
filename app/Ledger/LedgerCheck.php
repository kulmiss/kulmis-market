<?php

namespace App\Ledger;

use Illuminate\Support\Facades\DB;

/** Checks that the books add up: used by the tests, the simulations and the Money health page. */
final class LedgerCheck
{
    /** Every journal entry balances (its lines add up to 0), so all of them do. */
    public static function balanced(): bool
    {
        $total = (int) DB::table('ledger_postings')->sum('amount');
        $bad = DB::table('ledger_postings')->select('journal_entry_id')->groupBy('journal_entry_id')
            ->havingRaw('SUM(amount) <> 0')->limit(1)->count();

        return $total === 0 && $bad === 0;
    }
}
