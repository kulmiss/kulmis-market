<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property ?int $product_id
 * @property ?int $product_variant_id
 * @property string $name
 * @property int $quantity
 * @property int $list_unit_price
 * @property int $unit_price
 * @property int $unit_cost
 * @property int $line_total
 * @property bool $made_to_order
 * @property ?int $making_days
 */
class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'list_unit_price' => 'integer', 'unit_price' => 'integer', 'unit_cost' => 'integer',
            'line_total' => 'integer', 'made_to_order' => 'boolean', 'making_days' => 'integer'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
