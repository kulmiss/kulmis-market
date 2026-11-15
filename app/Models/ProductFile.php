<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file or private video link delivered after a digital product is paid.
 *
 * @property int $id
 * @property int $product_id
 * @property string $kind
 * @property string $title
 * @property ?string $path
 * @property ?int $size
 * @property ?string $video_url
 */
class ProductFile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path', 'video_url'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'video_url' => 'encrypted', 'position' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
