<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Bring N new paying members this month → get X." Paid after the month and
 * the refund window, within its monthly cap and the challenge budget.
 *
 * @property int $id
 * @property string $name
 * @property int $target
 * @property int $reward
 * @property ?int $monthly_cap
 * @property bool $is_active
 */
class Challenge extends Model
{
    protected $fillable = ['name', 'target', 'reward', 'monthly_cap', 'is_active'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['target' => 'integer', 'reward' => 'integer', 'monthly_cap' => 'integer', 'is_active' => 'boolean'];
    }
}
