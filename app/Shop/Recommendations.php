<?php

namespace App\Shop;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Frequently bought together" (from real paid orders) and "You may also like"
 * (same category, best rated first). Results are cached for six hours, so a
 * product page costs one small query on a cache miss and none after.
 */
class Recommendations
{
    public const TTL = 21600;

    /** @return list<int> product ids bought in the same paid orders, most often first */
    public function boughtTogetherIds(int $productId, int $limit = 3): array
    {
        return Cache::remember("reco.together.$productId.$limit", self::TTL, fn () => DB::table('order_items as a')
            ->join('order_items as b', fn ($j) => $j->on('b.order_id', '=', 'a.order_id')->on('b.product_id', '!=', 'a.product_id'))
            ->join('orders', 'orders.id', '=', 'a.order_id')
            ->whereNotNull('orders.paid_at')->where('a.product_id', $productId)->whereNotNull('b.product_id')
            ->groupBy('b.product_id')->orderByRaw('COUNT(*) DESC')->orderBy('b.product_id')
            ->limit($limit * 3)->pluck('b.product_id')->map(fn ($id) => (int) $id)->all());
    }

    /** @return Collection<int, Product> */
    public function boughtTogether(Product $product, bool $member, int $limit = 3): Collection
    {
        $ids = $this->boughtTogetherIds($product->id, $limit);
        if ($ids === []) {
            return new Collection;
        }

        return $this->load($ids, $member)->take($limit)->values();
    }

    /**
     * @param  list<int>  $exclude
     * @return Collection<int, Product>
     */
    public function alsoLike(Product $product, bool $member, int $limit = 4, array $exclude = []): Collection
    {
        $exclude[] = $product->id;
        $ids = Cache::remember("reco.like.$product->id", self::TTL, fn () => Product::query()->where('status', 'active')
            ->whereKeyNot($product->id)
            ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
            ->orderByDesc('rating_count')->orderByDesc('is_featured')->orderByDesc('id')->limit(12)->pluck('id')->all());

        // Load a few spare ones only (some may be hidden for this visitor).
        return $this->load(array_slice(array_values(array_diff($ids, $exclude)), 0, $limit + 2), $member)->take($limit)->values();
    }

    /**
     * For the cart and checkout: products often bought with what is in the cart,
     * then "also like" for the first item. Never something already in the cart.
     *
     * @param  list<int>  $cartProductIds
     * @return Collection<int, Product>
     */
    public function forCart(array $cartProductIds, bool $member, int $limit = 3): Collection
    {
        if ($cartProductIds === []) {
            return new Collection;
        }
        $ids = [];
        foreach (array_slice($cartProductIds, 0, 5) as $pid) {
            $ids = array_merge($ids, $this->boughtTogetherIds($pid, $limit));
        }
        $ids = array_values(array_unique(array_diff($ids, $cartProductIds)));
        $found = $this->load($ids, $member)->take($limit);
        if ($found->count() < $limit && ($first = Product::query()->find($cartProductIds[0]))) {
            $more = $this->alsoLike($first, $member, $limit, array_merge($cartProductIds, $found->modelKeys()));
            $found = $found->concat($more)->take($limit);
        }

        return new Collection($found->values()->all());
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    private function load(array $ids, bool $member): Collection
    {
        if ($ids === []) {
            return new Collection;
        }
        $rows = Product::query()->visibleTo($member)->whereKey($ids)->with(['images', 'variants'])->get()
            ->filter(fn (Product $p) => $p->isPurchasableBy($member) || $p->is_members_only)
            ->keyBy('id');

        return new Collection(array_values(array_filter(array_map(fn ($id) => $rows->get($id), $ids))));
    }
}
