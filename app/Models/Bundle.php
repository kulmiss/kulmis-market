<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Shop\Bundles;
use App\Storefront\PageCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A set of products sold together at one price. The saving is worked out
 * automatically in the cart when every piece of the set is there.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ?string $description
 * @property int $price
 * @property bool $is_active
 * @property ?Carbon $starts_at
 * @property ?Carbon $ends_at
 * @property-read Collection<int, BundleItem> $items
 */
class Bundle extends Model
{
    use HidesDemo;

    protected $fillable = ['name', 'slug', 'description', 'price', 'is_active', 'starts_at', 'ends_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        $changed = function (): void {
            app(Bundles::class)->forget();
            PageCache::clear();
        };
        static::saved($changed);
        static::deleted($changed);
    }

    protected function casts(): array
    {
        return ['price' => 'integer', 'is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** @return HasMany<BundleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class);
    }

    public function isLive(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->is_active && ($this->starts_at === null || $this->starts_at->lte($at)) && ($this->ends_at === null || $this->ends_at->gt($at));
    }

    /** What the pieces cost one by one at guest prices. */
    public function regularPrice(): int
    {
        return (int) $this->items->sum(fn (BundleItem $i) => ($i->variant->price ?? $i->product->price) * $i->quantity);
    }

    public function cost(): int
    {
        return (int) $this->items->sum(fn (BundleItem $i) => $i->product->totalCost() * $i->quantity);
    }
}
