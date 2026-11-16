<?php

namespace App\Shop;

use App\Models\Banner;
use App\Models\FlashSale;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Storefront\PageCache;
use App\Support\Features;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Live flash sales, loaded once per request (no query per product card).
 * The sale price is always taken from here on the server — never from the browser.
 */
class FlashSales
{
    private const CACHE = 'flash_sales.open';

    /** @var ?Collection<int, FlashSale> */
    private ?Collection $live = null;

    /** @return Collection<int, FlashSale> */
    public function live(): Collection
    {
        if (Features::disabled('shop.flash_sales')) {
            return collect();
        }

        // Sales that are on and not over yet, cached for a minute (no query on most pages).
        // "sold" may be a little old here: the checkout counts it again with a lock.
        // Plain rows in the cache (it refuses objects), models again here.
        $rows = Cache::remember(self::CACHE, 60, fn () => FlashSale::query()
            ->where('is_active', true)->where('ends_at', '>', now())->orderBy('price')->toBase()->get()->map(fn ($r) => (array) $r)->all());

        return $this->live ??= FlashSale::hydrate($rows)->filter(fn (FlashSale $s) => $s->isLive())->values()->toBase();
    }

    public function forget(): void
    {
        $this->live = null;
        Cache::forget(self::CACHE);
    }

    /**
     * The best live sale for this product (and option). A sale for one option
     * beats a sale for the whole product; the lower price wins otherwise.
     */
    public function for(Product $product, ?ProductVariant $variant = null): ?FlashSale
    {
        $sales = $this->live()->where('product_id', $product->id);
        if ($sales->isEmpty()) {
            return null;
        }
        if ($variant !== null && ($own = $sales->firstWhere('product_variant_id', $variant->id))) {
            return $own;
        }

        return $sales->first(fn (FlashSale $s) => $s->product_variant_id === null);
    }

    /** The sale to show on a product card / page (whole-product sale, or the cheapest option sale). */
    public function forDisplay(Product $product): ?FlashSale
    {
        $sales = $this->live()->where('product_id', $product->id);

        return $sales->firstWhere('product_variant_id', null) ?? $sales->first();
    }

    /**
     * Run every minute by the scheduler: when a sale starts or ends, guest pages
     * (which are cached) must show the new price.
     */
    public function tick(): bool
    {
        $stored = Cache::get('flash_sales.tick');
        $now = now();
        Cache::forever('flash_sales.tick', $now->toIso8601String());
        if (! is_string($stored)) {
            return false;
        }
        $last = Carbon::parse($stored);
        $changed = FlashSale::query()->where(fn ($q) => $q->whereBetween('starts_at', [$last, $now])->orWhereBetween('ends_at', [$last, $now]))->exists()
            // Banners (announcement bar) that start or end now, too.
            || Banner::query()->where(fn ($q) => $q->whereBetween('starts_at', [$last, $now])->orWhereBetween('ends_at', [$last, $now]))->exists();
        if ($changed) {
            PageCache::clear();
        }

        return $changed;
    }
}
