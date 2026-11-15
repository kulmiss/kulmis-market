<?php

namespace App\Models;

use App\Demo\HidesDemo;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property list<string> $cities
 * @property int $base_fee
 * @property int $included_grams
 * @property int $per_kg_fee
 * @property ?int $eta_days_min
 * @property ?int $eta_days_max
 * @property bool $is_default
 * @property bool $is_active
 */
class ShippingZone extends Model
{
    use HidesDemo;

    /** @var array<string, mixed> */
    protected $attributes = ['included_grams' => 1000, 'per_kg_fee' => 0, 'is_default' => false, 'is_active' => true, 'position' => 0];

    protected $fillable = ['name', 'cities', 'base_fee', 'included_grams', 'per_kg_fee', 'eta_days_min', 'eta_days_max', 'is_default', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['cities' => 'array', 'base_fee' => 'integer', 'included_grams' => 'integer', 'per_kg_fee' => 'integer',
            'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function feeFor(int $weightGrams): int
    {
        $extra = max(0, $weightGrams - $this->included_grams);

        return $this->base_fee + (int) ceil($extra / 1000) * $this->per_kg_fee;
    }

    public function servesCity(string $city): bool
    {
        $needle = mb_strtolower(trim($city));

        return collect($this->cities)->contains(fn ($c) => mb_strtolower(trim((string) $c)) === $needle);
    }
}
