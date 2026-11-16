<?php

namespace App\Shop;

use App\Catalog\ImageProcessor;
use App\Demo\Demo;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Storefront\PageCache;
use App\Support\Activity;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Product reviews: only people who bought the product and got it (order
 * delivered) can write one, one per product. Nothing shows before the
 * Super Admin approves it. Demo reviews show only while demo data is on.
 */
final class Reviews
{
    public const MAX_PHOTOS = 3;

    public function __construct(private readonly ImageProcessor $images) {}

    /** The delivered order that lets this person review this product, if any. */
    public function verifiedOrder(User $user, Product $product): ?Order
    {
        return Order::query()->where('user_id', $user->id)->where('status', Order::DELIVERED)
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))->latest('id')->first();
    }

    public function canReview(User $user, Product $product): bool
    {
        return $this->verifiedOrder($user, $product) !== null
            && ! ProductReview::query()->where('product_id', $product->id)->where('user_id', $user->id)->exists();
    }

    /**
     * @param  array{rating:int, title?:?string, body:string}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function submit(User $user, Product $product, array $data, array $photos = []): ProductReview
    {
        $order = $this->verifiedOrder($user, $product);
        if (! $order) {
            throw ValidationException::withMessages(['review' => 'You can review a product after you bought it and it was delivered.']);
        }
        if (ProductReview::query()->where('product_id', $product->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['review' => 'You already reviewed this product. Thank you!']);
        }
        $paths = [];
        foreach (array_slice($photos, 0, self::MAX_PHOTOS) as $file) {
            $paths[] = $this->images->photo($file, 'reviews/'.$product->id, 1200);
        }
        try {
            return ProductReview::query()->create([
                'product_id' => $product->id, 'user_id' => $user->id, 'order_id' => $order->id,
                'rating' => $data['rating'], 'title' => $data['title'] ?? null, 'body' => $data['body'],
                'photos' => $paths ?: null, 'status' => ProductReview::PENDING, 'is_demo' => (bool) $user->getAttribute('is_demo'),
            ]);
        } catch (QueryException $e) {
            Storage::disk('public')->delete($paths);
            throw ValidationException::withMessages(['review' => 'You already reviewed this product. Thank you!']);
        }
    }

    public function decide(ProductReview $review, bool $approve, int $actorId, ?string $reason = null): ProductReview
    {
        DB::transaction(function () use ($review, $approve, $actorId, $reason) {
            $review->update(['status' => $approve ? ProductReview::APPROVED : ProductReview::REJECTED,
                'reject_reason' => $approve ? null : $reason, 'reviewed_by' => $actorId, 'reviewed_at' => now()]);
            $this->refreshRating($review->product_id);
            Activity::log($approve ? 'review.approved' : 'review.rejected', $review, ['product_id' => $review->product_id], $reason, $actorId);
        });
        PageCache::clear();

        return $review;
    }

    /** Pre-computed average and count of approved real reviews. */
    public function refreshRating(int $productId): void
    {
        $row = ProductReview::query()->where('product_id', $productId)->where('status', ProductReview::APPROVED)->where('is_demo', false)
            ->selectRaw('COUNT(*) as c, AVG(rating) as a')->first();
        Product::query()->whereKey($productId)->update(['rating_count' => (int) $row?->getAttribute('c'),
            'rating_avg' => $row?->getAttribute('c') ? round((float) $row->getAttribute('a'), 2) : null]);
    }

    /**
     * What the product page shows: approved reviews (demo ones only while demo data is on).
     *
     * @return array{avg: ?float, count: int, stars: array<int, int>, items: Collection<int, ProductReview>}
     */
    public function forProduct(Product $product, int $limit = 10): array
    {
        $q = ProductReview::query()->where('product_id', $product->id)->where('status', ProductReview::APPROVED)
            ->when(! Demo::visible(), fn ($x) => $x->where('is_demo', false));
        $stars = (clone $q)->selectRaw('rating, COUNT(*) as c')->groupBy('rating')->pluck('c', 'rating')->map(fn ($c) => (int) $c)->all();
        $count = array_sum($stars);
        if ($count === 0) {
            return ['avg' => null, 'count' => 0, 'stars' => [], 'items' => new \Illuminate\Database\Eloquent\Collection];
        }
        $avg = round(array_sum(array_map(fn ($r, $c) => $r * $c, array_keys($stars), $stars)) / $count, 1);

        return ['avg' => $avg, 'count' => $count, 'stars' => $stars,
            'items' => $q->with('user:id,first_name,last_name')->latest('id')->limit($limit)->get()];
    }
}
