<?php

namespace App\Shipping;

use App\Models\ShippingZone;
use App\Pricing\PricingException;

/**
 * Price per city/zone + weight. Picks the zone by id, else by city name,
 * else the default ("Other cities") zone.
 */
class ZoneShippingQuoter implements ShippingQuoter
{
    public function quote(?int $zoneId, string $city, int $weightGrams): int
    {
        return $this->zoneFor($zoneId, $city)->feeFor($weightGrams);
    }

    public function zoneFor(?int $zoneId, string $city): ShippingZone
    {
        $zones = ShippingZone::query()->where('is_active', true)->orderBy('position')->get();
        if ($zones->isEmpty()) {
            throw new PricingException('Shipping is not set up yet. Please contact us.');
        }
        $zone = $zoneId ? $zones->firstWhere('id', $zoneId) : null;
        $zone ??= $zones->first(fn (ShippingZone $z) => $z->servesCity($city));
        $zone ??= $zones->firstWhere('is_default', true);
        if (! $zone) {
            throw new PricingException("Sorry, we don't deliver to “{$city}” yet.");
        }

        return $zone;
    }
}
