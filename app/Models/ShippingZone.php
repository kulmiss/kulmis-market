<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property list<string> $cities
 * @property int $base_fee
 * @property int $included_grams
 * @property int $per_kg_fee
 * @property int|null $courier_cost
 * @property int|null $courier_per_kg
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

    protected $fillable = ['name', 'cities', 'base_fee', 'included_grams', 'per_kg_fee', 'courier_cost', 'courier_per_kg', 'eta_days_min', 'eta_days_max', 'is_default', 'is_active', 'position'];

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

    /** A plain warning when the courier costs more than customers pay for delivery here (else null). */
    public function courierWarning(): ?string
    {
        $base = (int) ($this->courier_cost ?? $this->base_fee) - (int) $this->base_fee;
        $perKg = (int) ($this->courier_per_kg ?? $this->per_kg_fee) - (int) $this->per_kg_fee;
        if ($base <= 0 && $perKg <= 0) {
            return null;
        }

        return 'The courier costs more than customers pay for delivery here ('
            .Money::format(max(0, $base)).' more for the first kg). Orders that pay delivery lose the difference: raise the fee or find a cheaper courier.';
    }

    /** What the courier charges the shop for this parcel (empty = the same as the customer pays). */
    public function courierCostFor(int $weightGrams): int
    {
        $extra = max(0, $weightGrams - $this->included_grams);

        return (int) ($this->courier_cost ?? $this->base_fee) + (int) ceil($extra / 1000) * (int) ($this->courier_per_kg ?? $this->per_kg_fee);
    }

    public function servesCity(string $city): bool
    {
        $needle = mb_strtolower(trim($city));

        return collect($this->cities)->contains(fn ($c) => mb_strtolower(trim((string) $c)) === $needle);
    }
}
