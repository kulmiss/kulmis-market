<?php

namespace App\Shipping;

/** Placeholder until zones are configured (replaced in PR 7). */
class FreeShippingQuoter implements ShippingQuoter
{
    public function quote(?int $zoneId, string $city, int $weightGrams): int
    {
        return 0;
    }
}
