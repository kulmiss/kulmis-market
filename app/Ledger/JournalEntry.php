<?php

namespace App\Ledger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Immutable once written. Corrections are new (reversing) entries.
 *
 * @property int $id
 * @property string $uuid
 * @property string $type
 * @property ?string $description
 * @property ?string $reason
 * @property ?string $idempotency_key
 * @property ?int $reverses_entry_id
 * @property ?int $created_by
 */
class JournalEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'type', 'description', 'reason', 'idempotency_key', 'reference_type', 'reference_id', 'reverses_entry_id', 'created_by', 'is_demo'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Journal entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Journal entries are immutable.'));
    }

    /** @return HasMany<Posting, $this> */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class);
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }
}
