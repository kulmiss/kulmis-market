<?php

namespace App\Models;

use App\Demo\HidesDemo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $type
 * @property int $value
 * @property int $min_subtotal
 * @property ?int $max_discount
 * @property ?Carbon $starts_at
 * @property ?Carbon $ends_at
 * @property ?int $max_uses
 * @property ?int $max_uses_per_customer
 * @property int $used_count
 * @property bool $members_only
 * @property bool $is_active
 */
class Coupon extends Model
{
    use HidesDemo;

    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'members_only' => false, 'min_subtotal' => 0, 'used_count' => 0];

    protected $fillable = ['code', 'type', 'value', 'min_subtotal', 'max_discount', 'starts_at', 'ends_at',
        'max_uses', 'max_uses_per_customer', 'members_only', 'is_active', 'note'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'min_subtotal' => 'integer', 'max_discount' => 'integer', 'starts_at' => 'datetime',
            'ends_at' => 'datetime', 'max_uses' => 'integer', 'max_uses_per_customer' => 'integer', 'used_count' => 'integer',
            'members_only' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return Attribute<never, string> */
    protected function code(): Attribute
    {
        return Attribute::set(fn (string $v) => strtoupper(trim($v)));
    }

    public static function findByCode(string $code): ?self
    {
        return self::query()->where('code', strtoupper(trim($code)))->first();
    }
}
