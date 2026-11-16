<?php

namespace App\Models;

use App\Demo\HidesDemo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Make this for me": a description, photos and a budget. The admin answers
 * with a quote, which becomes an order paid as deposit + rest.
 *
 * @property int $id
 * @property ?int $user_id
 * @property string $name
 * @property string $phone
 * @property ?string $email
 * @property string $city
 * @property string $address
 * @property string $description
 * @property ?list<string> $photos
 * @property ?int $budget
 * @property string $status
 * @property ?int $order_id
 */
class CustomRequest extends Model
{
    use HidesDemo;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['photos' => 'array', 'budget' => 'integer'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
