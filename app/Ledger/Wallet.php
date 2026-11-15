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

    /** @return array{pending:int, available:int, payout:int} */
    public function summary(): array
    {
        return ['pending' => $this->pending(), 'available' => $this->available(), 'payout' => $this->onPayoutHold()];
    }

    public function account(string $bucket): Account
    {
        return $this->ledger->userAccount($this->user, $bucket);
    }
}
