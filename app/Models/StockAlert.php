<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property ?int $product_variant_id
 * @property ?int $user_id
 * @property string $contact
 * @property string $channel
 * @property ?Carbon $notified_at
 */
class StockAlert extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
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
}
