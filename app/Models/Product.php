<?php

namespace App\Models;

use App\Events\CatalogChanged;
use App\Support\Features;
use App\Support\Settings;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ?int $category_id
 * @property int $price
 * @property int $cost_materials
 * @property int $cost_labour
 * @property int $cost_packaging
 * @property int $weight_grams
 * @property string $stock_mode
 * @property ?int $stock
 * @property ?int $making_days
 * @property bool $is_featured
 * @property bool $is_members_only
 * @property ?Carbon $release_at
 * @property string $status
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    public const STOCK = 'stock';

    public const MADE_TO_ORDER = 'made_to_order';

    public const STATUSES = ['draft', 'active', 'archived'];

    /** @var array<string, mixed> */
    protected $attributes = ['stock_mode' => self::STOCK, 'status' => 'draft', 'is_featured' => false, 'is_members_only' => false, 'weight_grams' => 0, 'cost_materials' => 0, 'cost_labour' => 0, 'cost_packaging' => 0];

    protected $fillable = [
        'name', 'slug', 'short_description', 'description', 'category_id', 'price',
        'cost_materials', 'cost_labour', 'cost_packaging', 'weight_grams', 'stock_mode', 'stock',
        'making_days', 'is_featured', 'is_members_only', 'release_at', 'status', 'seo_title', 'seo_description',
    ];

    /** Cost fields never leave the admin. */
    protected $hidden = ['cost_materials', 'cost_labour', 'cost_packaging'];

    protected $dispatchesEvents = ['saved' => CatalogChanged::class, 'deleted' => CatalogChanged::class];

    protected function casts(): array
    {
        return [
            'price' => 'integer', 'cost_materials' => 'integer', 'cost_labour' => 'integer',
            'cost_packaging' => 'integer', 'weight_grams' => 'integer', 'stock' => 'integer',
            'making_days' => 'integer', 'is_featured' => 'boolean', 'is_members_only' => 'boolean',
            'release_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function totalCost(): int
    {
        return $this->cost_materials + $this->cost_labour + $this->cost_packaging;
    }

    public function isMadeToOrder(): bool
    {
        return $this->stock_mode === self::MADE_TO_ORDER;
    }

    /** The moment members may see it (48 h before the public by default). */
    public function memberReleaseAt(): ?Carbon
    {
        return $this->release_at?->copy()->subHours(Settings::int('catalog.early_access_hours'));
    }

    public function isReleasedFor(bool $member, ?Carbon $now = null): bool
    {
        $now ??= now();
        if ($this->release_at === null || $this->release_at->lte($now)) {
            return true;
        }

        return $member && Features::enabled('shop.early_access') && $this->memberReleaseAt()?->lte($now);
    }

    /** In early access right now (members see it, guests don't yet). */
    public function inEarlyAccess(?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->release_at !== null && $this->release_at->gt($now) && (bool) $this->memberReleaseAt()?->lte($now);
    }

    /** Members-only products are visible to everyone but sold only to members. */
    public function isPurchasableBy(bool $member): bool
    {
        if ($this->status !== 'active' || ! $this->isReleasedFor($member)) {
            return false;
        }
        if ($this->is_members_only && Features::enabled('shop.members_only_product')) {
            return $member;
        }

        return true;
    }

    /**
     * Admin warnings: price below N× cost (default 2.5×). Profitability with
     * discounts and commissions is added by the pricing engine (PR 5).
     *
     * @return list<array{code:string,message:string}>
     */
    public function priceWarnings(): array
    {
        $warnings = [];
        $ratio = Settings::int('catalog.min_price_cost_ratio'); // ×100
        $cost = $this->totalCost();
        $prices = [$this->price];
        foreach ($this->relationLoaded('variants') ? $this->variants : $this->variants()->get() as $v) {
            if ($v->price !== null) {
                $prices[] = $v->price;
            }
        }
        if ($cost > 0 && min($prices) * 100 < $cost * $ratio) {
            $warnings[] = ['code' => 'price_below_cost_ratio', 'message' => sprintf(
                'Price is below %s× cost (cost %s).', rtrim(rtrim(number_format($ratio / 100, 2), '0'), '.'), number_format($cost / 100, 2))];
        }

        return $warnings;
    }

    /** Total units available (null = unlimited / made to order). */
    public function availableStock(): ?int
    {
        if ($this->isMadeToOrder()) {
            return null;
        }
        $variants = $this->relationLoaded('variants') ? $this->variants : $this->variants()->get();
        if ($variants->isNotEmpty()) {
            return (int) $variants->where('is_active', true)->sum('stock');
        }

        return (int) $this->stock;
    }

    /**
     * Products a visitor may browse.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeVisibleTo(Builder $query, bool $member): void
    {
        $now = now();
        $query->where('status', 'active')->where(function (Builder $q) use ($member, $now) {
            $q->whereNull('release_at')->orWhere('release_at', '<=', $now);
            if ($member && Features::enabled('shop.early_access')) {
                $q->orWhere('release_at', '<=', $now->copy()->addHours(Settings::int('catalog.early_access_hours')));
            }
        });
    }
}
