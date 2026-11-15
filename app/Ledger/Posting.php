<?php

namespace App\Ledger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $journal_entry_id
 * @property int $ledger_account_id
 * @property int $amount
 */
class Posting extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ledger_postings';

    protected $fillable = ['journal_entry_id', 'ledger_account_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Postings are immutable.'));
        static::deleting(fn () => throw new LogicException('Postings are immutable.'));
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ledger_account_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
