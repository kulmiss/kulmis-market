<?php

namespace App\Pricing;

use App\Models\Product;
use App\Models\ProductVariant;

/** One priced cart line. All money in santim. */
final class Line
{
    public function __construct(
        public readonly Product $product,
        public readonly ?ProductVariant $variant,
        public readonly int $quantity,
        public readonly int $listUnitPrice,      // guest price
        public readonly int $unitPrice,          // after member discount
        public readonly int $memberUnitPrice,    // what a member would pay (for "Member price" display)
    ) {}

    public function listTotal(): int
    {
        return $this->listUnitPrice * $this->quantity;
    }

    public function total(): int
    {
        return $this->unitPrice * $this->quantity;
    }

    public function weightGrams(): int
    {
        return ($this->variant?->weight_grams ?? $this->product->weight_grams) * $this->quantity;
    }

    public function unitCost(): int
    {
        return $this->product->totalCost();
    }
}
