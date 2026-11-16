<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Shop\FlashSales;
use App\Storefront\PageCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A short sale: a lower guest price between two times, optionally for a
 * limited number of pieces. Members get their member price on top.
 *
 * @property int $id
 * @property int $product_id
 * @property ?int $product_variant_id
 * @property int $price
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property ?int $stock_limit
 * @property int $sold
 * @property bool $is_active
 * @property-read Product $product
 * @property-read ?ProductVariant $variant
 */
class FlashSale extends Model
{
    use HidesDemo;

    protected $fillable = ['product_id', 'product_variant_id', 'price', 'starts_at', 'ends_at', 'stock_limit', 'is_active'];

    /** @var array<string, mixed> */
    protected $attributes = ['sold' => 0, 'is_active' => true];

    protected static function booted(): void
    {
        $changed = function (): void {
            app(FlashSales::class)->forget();
            PageCache::clear();
        };
        static::saved($changed);
        static::deleted($changed);
    }

    protected function casts(): array
    {
        return ['price' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'stock_limit' => 'integer',
            'sold' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Pieces still available at the sale price (null = no limit). */
    public function remaining(): ?int
    {
        return $this->stock_limit === null ? null : max(0, $this->stock_limit - $this->sold);
    }

    public function isLive(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->is_active && $this->starts_at->lte($at) && $this->ends_at->gt($at) && ($this->remaining() ?? 1) > 0;
    }

    public function status(): string
    {
        $now = now();

        return match (true) {
            ! $this->is_active => 'off',
            $this->ends_at->lte($now) => 'ended',
            $this->remaining() === 0 => 'sold_out',
            $this->starts_at->gt($now) => 'scheduled',
            default => 'live',
        };
    }
}
