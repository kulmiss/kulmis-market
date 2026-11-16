<?php

namespace App\Models;

use App\Demo\HidesDemo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property int $amount
 * @property int $reversed_amount
 * @property string $status
 * @property bool $on_hold
 * @property ?Carbon $available_at
 * @property ?Carbon $released_at
 * @property ?string $source_type
 * @property ?int $source_id
 * @property string $dedupe_key
 * @property string $description
 * @property ?Carbon $created_at
 */
class Reward extends Model
{
    use HidesDemo;

    public const PENDING = 'pending';

    public const AVAILABLE = 'available';

    public const REVERSED = 'reversed';

    public const KINDS = ['first_payment' => 'First-payment bonus', 'contest' => 'Contest prize', 'challenge' => 'Team challenge', 'comeback' => 'Welcome-back reward'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'reversed_amount' => 'integer', 'on_hold' => 'boolean',
            'available_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function outstanding(): int
    {
        return $this->amount - $this->reversed_amount;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
