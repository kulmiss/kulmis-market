<?php

namespace App\Models;

use App\Demo\HidesDemo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payment_id
 * @property int $amount
 * @property string $reason
 * @property string $method
 */
class Refund extends Model
{
    use HidesDemo;

    /** @return list<string> */
    protected static function demoOwnerColumns(): array
    {
        return ['created_by'];
    }

    /** @return array<string, string> */
    protected static function demoParents(): array
    {
        return ['payment_id' => 'payments'];
    }

    public const UPDATED_AT = null;

    protected $fillable = ['payment_id', 'amount', 'reason', 'method', 'provider_reference', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
