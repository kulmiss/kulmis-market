<?php

namespace App\Models;

use App\Demo\HidesDemo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $source_type
 * @property int $source_id
 * @property ?int $payment_id
 * @property int $recipient_id
 * @property ?int $buyer_user_id
 * @property int $level
 * @property string $type
 * @property int $base_amount
 * @property int $rate_bps
 * @property int $amount
 * @property int $reversed_amount
 * @property string $status
 * @property ?string $skip_reason
 * @property ?Carbon $available_at
 * @property ?Carbon $released_at
 * @property bool $on_hold
 */
class Commission extends Model
{
    use HidesDemo;

    /** @return list<string> */
    protected static function demoOwnerColumns(): array
    {
        return ['recipient_id', 'buyer_user_id'];
    }

    /** @return array<string, string> */
    protected static function demoParents(): array
    {
        return ['payment_id' => 'payments'];
    }

    public const PENDING = 'pending';

    public const AVAILABLE = 'available';

    public const REVERSED = 'reversed';

    public const SKIPPED = 'skipped';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'base_amount' => 'integer', 'rate_bps' => 'integer', 'amount' => 'integer',
            'reversed_amount' => 'integer', 'available_at' => 'datetime', 'released_at' => 'datetime', 'on_hold' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function outstanding(): int
    {
        return $this->amount - $this->reversed_amount;
    }
}
