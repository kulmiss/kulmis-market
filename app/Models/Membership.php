<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Support\Features;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property ?Carbon $current_period_start
 * @property ?Carbon $current_period_end
 * @property ?Carbon $grace_ends_at
 * @property ?Carbon $continuous_since
 * @property int $periods_paid
 */
class Membership extends Model
{
    use HidesDemo;

    protected $fillable = ['user_id', 'status', 'current_period_start', 'current_period_end', 'grace_ends_at', 'continuous_since', 'periods_paid'];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime', 'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime', 'continuous_since' => 'datetime', 'periods_paid' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Benefits run until the end of the grace period. While membership is
     * switched off nobody can pay, so there is no grace: access ends with
     * the paid month.
     */
    public function accessEndsAt(): ?Carbon
    {
        if (Features::disabled('membership')) {
            return $this->current_period_end;
        }

        return $this->grace_ends_at ?? $this->current_period_end;
    }

    public function isActive(?Carbon $at = null): bool
    {
        $at ??= now();
        $end = $this->accessEndsAt();

        return in_array($this->status, ['active', 'grace'], true) && $end !== null && $end->gte($at);
    }
}
