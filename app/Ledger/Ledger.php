<?php

namespace App\Ledger;

use App\Models\User;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Double-entry ledger. All money movements go through post(); balances are
 * always computed from postings and never stored or edited.
 *
 * Sign convention: + debit, − credit. Every entry must sum to zero.
 */
class Ledger
{
    /**
     * @param  list<array{0: Account|string, 1: int}>  $lines  [account, amount]
     * @param  list<Account|string>  $nonNegative  accounts whose natural balance must stay ≥ 0
     */
    public function post(
        string $type,
        array $lines,
        ?string $idempotencyKey = null,
        ?Model $reference = null,
        ?string $reason = null,
        ?int $actorId = null,
        ?string $description = null,
        array $nonNegative = [],
        ?int $reversesEntryId = null,
    ): JournalEntry {
        if (str_starts_with($type, 'manual.') && trim((string) $reason) === '') {
            throw new LedgerException('Manual ledger entries need a reason.');
        }
        if (count($lines) < 2) {
            throw new LedgerException('An entry needs at least two lines.');
        }
        $sum = 0;
        foreach ($lines as [, $amount]) {
            if (! is_int($amount) || $amount === 0) {
                throw new LedgerException('Line amounts must be non-zero integers (santim).');
            }
            $sum += $amount;
        }
        if ($sum !== 0) {
            throw new LedgerException("Entry is not balanced (off by $sum).");
        }

        if ($idempotencyKey !== null && ($existing = JournalEntry::query()->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($type, $lines, $idempotencyKey, $reference, $reason, $actorId, $description, $nonNegative, $reversesEntryId) {
                $accounts = [];
                foreach ($lines as [$account]) {
                    $acc = $this->resolve($account);
                    $accounts[$acc->id] = $acc;
                }
                foreach ($nonNegative as $account) {
                    $acc = $this->resolve($account);
                    $accounts[$acc->id] = $acc;
                }
                // Lock in a stable order to avoid deadlocks.
                ksort($accounts);
                Account::query()->whereKey(array_keys($accounts))->orderBy('id')->lockForUpdate()->get();

                $entry = JournalEntry::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'type' => $type,
                    'description' => $description,
                    'reason' => $reason,
                    'idempotency_key' => $idempotencyKey,
                    'reference_type' => $reference?->getMorphClass(),
                    'reference_id' => $reference?->getKey(),
                    'reverses_entry_id' => $reversesEntryId,
                    'created_by' => $actorId,
                ]);
                foreach ($lines as [$account, $amount]) {
                    Posting::query()->create([
                        'journal_entry_id' => $entry->id,
                        'ledger_account_id' => $this->resolve($account)->id,
                        'amount' => $amount,
                    ]);
                }
                foreach ($nonNegative as $account) {
                    $acc = $this->resolve($account);
                    if ($this->balance($acc) < 0) {
                        throw new InsufficientFunds("Insufficient balance in [{$acc->code}].");
                    }
                }
                if (str_starts_with($type, 'manual.')) {
                    Activity::log('ledger.manual_entry', $entry, ['type' => $type, 'lines' => array_map(
                        fn ($l) => [$this->resolve($l[0])->code, $l[1]], $lines)], $reason, $actorId);
                }

                return $entry;
            });
        } catch (QueryException $e) {
            // Lost a race on the idempotency key: return the winner.
            if ($idempotencyKey !== null && ($existing = JournalEntry::query()->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }
            throw $e;
        }
    }

    /** Post the exact opposite of an entry (optionally a fraction of it). */
    public function reverse(JournalEntry $entry, string $type, ?string $reason = null, ?string $idempotencyKey = null, ?int $actorId = null, ?int $numerator = null, ?int $denominator = null): JournalEntry
    {
        $lines = [];
        foreach ($entry->postings()->with('account')->get() as $p) {
            $amount = -$p->amount;
            if ($numerator !== null && $denominator) {
                $amount = intdiv($amount * $numerator, $denominator);
            }
            $lines[] = [$p->account, $amount];
        }
        $lines = $this->rebalance($lines);

        return $this->post($type, $lines, $idempotencyKey, $entry->reference, $reason, $actorId, 'Reversal of '.$entry->uuid, [], $entry->id);
    }

    /** Natural balance: debit-normal accounts sum(+), credit-normal accounts −sum. */
    public function balance(Account|string $account): int
    {
        $acc = $this->resolve($account);
        $sum = (int) Posting::query()->where('ledger_account_id', $acc->id)->sum('amount');

        return $acc->isDebitNormal() ? $sum : -$sum;
    }

    public function account(string $code, string $type = Account::ASSET, ?string $name = null, ?int $userId = null): Account
    {
        return Account::query()->firstOrCreate(['code' => $code], ['type' => $type, 'name' => $name ?? $code, 'user_id' => $userId]);
    }

    /** Member wallet accounts (liabilities: money the shop owes the member). */
    public function userAccount(User|int $user, string $bucket): Account
    {
        $id = $user instanceof User ? $user->id : $user;
        if (! in_array($bucket, Wallet::BUCKETS, true)) {
            throw new LedgerException("Unknown wallet bucket [$bucket].");
        }

        return $this->account("user:$id:$bucket", Account::LIABILITY, "Wallet $bucket #$id", $id);
    }

    public function resolve(Account|string $account): Account
    {
        if ($account instanceof Account) {
            return $account;
        }
        $type = match (strtok($account, ':')) {
            'asset' => Account::ASSET,
            'income' => Account::INCOME,
            'expense' => Account::EXPENSE,
            'equity' => Account::EQUITY,
            'user', 'liability' => Account::LIABILITY,
            default => throw new LedgerException("Unknown account [$account]."),
        };
        if (str_starts_with($account, 'user:')) {
            [, $id, $bucket] = explode(':', $account) + [null, null, null];

            return $this->userAccount((int) $id, (string) $bucket);
        }

        return $this->account($account, $type);
    }

    /**
     * Partial reversals can lose a santim to rounding; put the remainder on
     * the largest line so the entry still sums to zero.
     *
     * @param  list<array{0: Account, 1: int}>  $lines
     * @return list<array{0: Account, 1: int}>
     */
    private function rebalance(array $lines): array
    {
        $lines = array_values(array_filter($lines, fn ($l) => $l[1] !== 0));
        $sum = array_sum(array_column($lines, 1));
        if ($sum !== 0 && $lines) {
            usort($lines, fn ($a, $b) => abs($b[1]) <=> abs($a[1]));
            $lines[0][1] -= $sum;
        }

        return array_values(array_filter($lines, fn ($l) => $l[1] !== 0));
    }
}
