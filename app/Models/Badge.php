<?php

namespace App\Models;

use App\Support\Features;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property string $icon
 * @property string $color
 * @property string $rule
 * @property int $threshold
 * @property int $rank
 * @property bool $is_active
 * @property int $monthly_bonus santim, paid every month (level-1 team badges only)
 * @property int $one_time_bonus santim, paid once when unlocked (other badges)
 */
class Badge extends Model
{
    protected static function booted(): void
    {
        // Each badge has a switch on the Feature switches page.
        static::saved(fn () => Features::forgetMemo());
        static::deleted(fn () => Features::forgetMemo());
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['threshold' => 'integer', 'rank' => 'integer', 'is_active' => 'boolean', 'monthly_bonus' => 'integer', 'one_time_bonus' => 'integer'];
    }

    /** Only the level-1 team badges (Bronze … Diamond) can pay every month. */
    public function paysMonthly(): bool
    {
        return $this->rule === 'active_level1';
    }

    /** @return HasMany<UserBadge, $this> */
    public function holders(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }
}
