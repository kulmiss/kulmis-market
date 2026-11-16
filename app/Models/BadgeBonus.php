<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One member's badge bonus for one month (monthly) or one badge (one-time).
 *
 * @property int $id
 * @property int $run_id
 * @property int $user_id
 * @property int $badge_id
 * @property Carbon $month
 * @property string $kind
 * @property int $wanted
 * @property int $amount
 * @property int $reduced_amount
 * @property ?string $limited_by
 * @property int $team_money
 * @property int $team_active
 * @property string $status
 * @property bool $on_hold
 * @property ?Carbon $available_at
 * @property ?Carbon $released_at
 * @property ?int $journal_entry_id
 * @property bool $is_demo
 */
class BadgeBonus extends Model
{
    public const PENDING = 'pending';

    public const AVAILABLE = 'available';

    public const REDUCED = 'reduced'; // fully taken back before it was ready

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'wanted' => 'integer', 'amount' => 'integer', 'reduced_amount' => 'integer', 'team_money' => 'integer',
            'team_active' => 'integer', 'on_hold' => 'boolean', 'available_at' => 'datetime', 'released_at' => 'datetime', 'is_demo' => 'boolean'];
    }

    public function outstanding(): int
    {
        return max(0, $this->amount - $this->reduced_amount);
    }

    /** @return BelongsTo<Badge, $this> */
    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<BadgeBonusRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BadgeBonusRun::class, 'run_id');
    }
}
