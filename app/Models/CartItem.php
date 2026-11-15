<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $product_id
 * @property ?int $product_variant_id
 * @property int $quantity
 * @property Product $product
 * @property ?ProductVariant $variant
 */
class CartItem extends Model
{
    protected $fillable = ['product_id', 'product_variant_id', 'quantity'];

    /**
     * A change to a line counts as a change to the cart (for the cart reminder).
     *
     * @var list<string>
     */
    protected $touches = ['cart'];

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
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
