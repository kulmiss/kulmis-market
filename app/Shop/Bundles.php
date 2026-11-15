<?php

namespace App\Shop;

use App\Models\Bundle;
use App\Models\BundleItem;
use App\Pricing\Line;
use App\Pricing\PriceCalculator;
use App\Support\Features;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Bundle savings: when the cart holds every piece of a set, the set costs the
 * bundle price (members get their member price on top). Each piece counts for
 * one set only, and the biggest saving is used first.
 */
class Bundles
{
    private const CACHE = 'bundles.open';

    /** @var ?Collection<int, Bundle> */
    private ?Collection $live = null;

    /** @return Collection<int, Bundle> */
    public function live(): Collection
    {
        if (Features::disabled('shop.bundles')) {
            return collect();
        }

        // Cached for five minutes; any product or bundle change clears it.
        return $this->live ??= Cache::remember(self::CACHE, 300, fn () => Bundle::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->with(['items.product.images', 'items.product.variants', 'items.variant'])->get())
            ->filter(fn (Bundle $b) => $b->isLive() && $b->items->isNotEmpty() && $b->items->every(fn (BundleItem $i) => $i->product->status === 'active'))
            ->values();
    }

    public function forget(): void
    {
        $this->live = null;
        Cache::forget(self::CACHE);
    }

    /** @return Collection<int, Bundle> */
    public function containing(int $productId): Collection
    {
        return $this->live()->filter(fn (Bundle $b) => $b->items->contains('product_id', $productId))->values();
    }

    public function setPrice(Bundle $bundle, bool $member): int
    {
        return $member ? app(PriceCalculator::class)->memberPrice($bundle->price) : $bundle->price;
    }

    /**
     * @param  list<Line>  $lines
     * @return array{discount:int, applied:list<array{bundle_id:int,name:string,sets:int,saving:int}>}
     */
    public function apply(array $lines, bool $member): array
    {
        $bundles = $this->live();
        if ($bundles->isEmpty()) {
            return ['discount' => 0, 'applied' => []];
        }
        $qty = [];
        $unit = [];
        foreach ($lines as $l) {
            $key = $l->product->id.':'.($l->variant->id ?? 0);
            $qty[$key] = ($qty[$key] ?? 0) + $l->quantity;
            $unit[$key] = $l->unitPrice;
        }

        // Saving of one set at today's cart prices.
        $candidates = [];
        foreach ($bundles as $b) {
            $pieces = 0;
            foreach ($b->items as $i) {
                $key = $i->product_id.':'.($i->product_variant_id ?? 0);
                if (! isset($unit[$key])) {
                    continue 2;
                }
                $pieces += $unit[$key] * $i->quantity;
            }
            $saving = $pieces - $this->setPrice($b, $member);
            if ($saving > 0) {
                $candidates[] = [$b, $saving];
            }
        }
        usort($candidates, fn ($a, $b) => $b[1] <=> $a[1]);

        $discount = 0;
        $applied = [];
        foreach ($candidates as [$b, $saving]) {
            $sets = 99;
            foreach ($b->items as $i) {
                $sets = min($sets, intdiv($qty[$i->product_id.':'.($i->product_variant_id ?? 0)] ?? 0, max(1, $i->quantity)));
            }
            if ($sets < 1) {
                continue;
            }
            foreach ($b->items as $i) {
                $qty[$i->product_id.':'.($i->product_variant_id ?? 0)] -= $sets * $i->quantity;
            }
            $discount += $sets * $saving;
            $applied[] = ['bundle_id' => $b->id, 'name' => $b->name, 'sets' => $sets, 'saving' => $sets * $saving];
        }

        return ['discount' => $discount, 'applied' => $applied];
    }
}
