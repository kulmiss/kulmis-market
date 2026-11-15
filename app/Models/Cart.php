<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $token
 * @property ?int $user_id
 * @property ?string $coupon_code
 * @property ?Carbon $reminded_at
 * @property-read ?User $user
 */
class Cart extends Model
{
    protected $fillable = ['token', 'user_id', 'coupon_code', 'reminded_at'];

    protected function casts(): array
    {
        return ['reminded_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
