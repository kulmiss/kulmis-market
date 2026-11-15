<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property int $user_id
 * @property ?int $order_id
 * @property int $rating
 * @property ?string $title
 * @property string $body
 * @property list<string>|null $photos
 * @property string $status
 * @property ?string $reject_reason
 * @property ?Carbon $reviewed_at
 * @property bool $is_demo
 */
class ProductReview extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'photos' => 'array', 'reviewed_at' => 'datetime', 'is_demo' => 'boolean'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "Abebe K." — never the full name. */
    public function authorName(): string
    {
        $u = $this->user;

        return $u ? trim($u->first_name.' '.mb_substr((string) $u->last_name, 0, 1).($u->last_name ? '.' : '')) : 'Customer';
    }
}
