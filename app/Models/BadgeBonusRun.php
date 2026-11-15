<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One finished month of badge bonuses (paid or skipped).
 *
 * @property int $id
 * @property Carbon $month
 * @property string $status
 * @property int $revenue
 * @property int $budget
 * @property int $wanted
 * @property int $paid
 * @property int $members
 * @property bool $adjusted
 * @property ?string $note
 * @property ?int $actor_id
 * @property bool $is_demo
 */
class BadgeBonusRun extends Model
{
    public const PAID = 'paid';

    public const SKIPPED = 'skipped';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'revenue' => 'integer', 'budget' => 'integer', 'wanted' => 'integer', 'paid' => 'integer',
            'members' => 'integer', 'adjusted' => 'boolean', 'is_demo' => 'boolean'];
    }

    /** @return HasMany<BadgeBonus, $this> */
    public function bonuses(): HasMany
    {
        return $this->hasMany(BadgeBonus::class, 'run_id');
    }

    /** The share everyone got when the budget was too small (1 = full bonus). */
    public function allows(int $afterTeamLimit): int
    {
        return $this->adjusted ? intdiv($afterTeamLimit * $this->budget, max(1, $this->wanted)) : $afterTeamLimit;
    }
}
