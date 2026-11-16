<?php

namespace App\Models;

use App\Events\CatalogChanged;
use App\Jobs\NotifyBackInStock;
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
    protected static function booted(): void
    {
        // Sold out → back in stock: tell the people who asked for this option.
        static::updated(function (ProductVariant $v) {
            if ($v->wasChanged('stock') && (int) $v->getOriginal('stock') < 1 && (int) $v->stock > 0) {
                NotifyBackInStock::dispatch($v->product_id, $v->id)->afterCommit();
            }
        });
    }

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
