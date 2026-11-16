<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property int $product_file_id
 * @property string $token_hash
 * @property int $downloads
 * @property int $max_downloads
 * @property Carbon $expires_at
 * @property-read ProductFile $file
 * @property-read Order $order
 */
class DownloadGrant extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['downloads' => 'integer', 'max_downloads' => 'integer', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<ProductFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(ProductFile::class, 'product_file_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
