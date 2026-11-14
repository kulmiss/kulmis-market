<?php

namespace App\Models;

use App\Events\CatalogChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property ?string $sku
 * @property string $name
 * @property ?array<string,string> $options
 * @property ?int $price
 * @property ?int $weight_grams
 * @property ?int $stock
 * @property bool $is_active
 */
class ProductVariant extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'position' => 0];

    protected $fillable = ['sku', 'name', 'options', 'price', 'weight_grams', 'stock', 'position', 'is_active'];

    protected $dispatchesEvents = ['saved' => CatalogChanged::class, 'deleted' => CatalogChanged::class];

    protected function casts(): array
    {
        return ['options' => 'array', 'price' => 'integer', 'weight_grams' => 'integer', 'stock' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
