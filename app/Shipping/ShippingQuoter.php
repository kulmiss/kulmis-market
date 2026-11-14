<?php

namespace App\Shipping;

use App\Pricing\PricingException;

/** Shipping price for a destination and parcel weight (santim). */
interface ShippingQuoter
{
    /** @throws PricingException when the destination is not served */
    public function quote(?int $zoneId, string $city, int $weightGrams): int;
}
