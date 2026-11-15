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
        return ['threshold' => 'integer', 'rank' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return HasMany<UserBadge, $this> */
    public function holders(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }
}
