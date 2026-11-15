<?php

namespace App\Models;

use App\Demo\HidesDemo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A trading signal. Once closed its result is locked: no more edits.
 *
 * @property int $id
 * @property string $pair
 * @property string $direction
 * @property string $entry
 * @property list<string> $targets
 * @property string $stop_loss
 * @property ?string $notes
 * @property string $status
 * @property ?string $result
 * @property ?string $result_value
 * @property ?string $result_note
 * @property ?int $telegram_message_id
 * @property ?Carbon $posted_at
 * @property ?Carbon $closed_at
 * @property ?Carbon $locked_at
 */
class Signal extends Model
{
    use HidesDemo;

    public const RESULTS = ['win', 'loss', 'breakeven', 'cancelled'];

    protected $fillable = ['pair', 'direction', 'entry', 'targets', 'stop_loss', 'notes', 'status', 'result', 'result_value',
        'result_note', 'telegram_message_id', 'created_by', 'posted_at', 'closed_at', 'locked_at'];

    protected function casts(): array
    {
        return ['targets' => 'array', 'posted_at' => 'datetime', 'closed_at' => 'datetime', 'locked_at' => 'datetime', 'telegram_message_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (Signal $s) {
            if ($s->getOriginal('locked_at') !== null) {
                throw new LogicException('This signal is closed and locked.');
            }
        });
        static::deleting(fn () => throw new LogicException('Signals are never deleted (results log).'));
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
