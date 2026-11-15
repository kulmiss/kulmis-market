<?php

namespace App\Models;

use App\Demo\HidesDemo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $badge_id
 * @property Carbon $unlocked_at
 * @property Carbon|null $seen_at
 * @property-read Badge $badge
 */
class UserBadge extends Model
{
    use HidesDemo;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unlocked_at' => 'datetime', 'seen_at' => 'datetime'];
    }

    /** @return BelongsTo<Badge, $this> */
    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }
}
