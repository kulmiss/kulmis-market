<?php

namespace App\Ledger;

use App\Models\User;

/**
 * A member's wallet, read from the ledger.
 * - pending:   earnings inside the refund window (not spendable)
 * - available: spendable / withdrawable
 * - payout:    requested cash-outs waiting for Super Admin
 */
class Wallet
{
    public const PENDING = 'pending';

    public const AVAILABLE = 'available';

    public const PAYOUT = 'payout';

    public const BUCKETS = [self::PENDING, self::AVAILABLE, self::PAYOUT];

    public function __construct(private readonly Ledger $ledger, private readonly User $user) {}

    public static function for(User $user): self
    {
        return new self(app(Ledger::class), $user);
    }

    public function pending(): int
    {
        return $this->ledger->balance($this->ledger->userAccount($this->user, self::PENDING));
    }

    public function available(): int
    {
        return $this->ledger->balance($this->ledger->userAccount($this->user, self::AVAILABLE));
    }

    public function onPayoutHold(): int
    {
        return $this->ledger->balance($this->ledger->userAccount($this->user, self::PAYOUT));
    }

    /**
     * All three balances in one query (the member pages show them together).
     *
     * @return array{pending:int, available:int, payout:int}
     */
    public function summary(): array
    {
        $codes = array_map(fn (string $b) => "user:{$this->user->id}:$b", self::BUCKETS);
        $sums = Account::query()->whereIn('code', $codes)
            ->leftJoin('ledger_postings', 'ledger_postings.ledger_account_id', '=', 'ledger_accounts.id')
            ->groupBy('ledger_accounts.id', 'ledger_accounts.code')
            ->selectRaw('ledger_accounts.code, COALESCE(SUM(ledger_postings.amount), 0) as total')
            ->pluck('total', 'code');
        // Wallet accounts are liabilities: the balance is the credit side (negative postings).
        $get = fn (string $b) => -(int) ($sums["user:{$this->user->id}:$b"] ?? 0);

        return ['pending' => $get(self::PENDING), 'available' => $get(self::AVAILABLE), 'payout' => $get(self::PAYOUT)];
    }

    public function account(string $bucket): Account
    {
        return $this->ledger->userAccount($this->user, $bucket);
    }
}
