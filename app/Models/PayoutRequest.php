<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $amount
 * @property string $method
 * @property string $account_name
 * @property string $account_number
 * @property string $account_fingerprint
 * @property ?string $bank_name
 * @property string $status
 * @property Carbon $due_at
 * @property ?Carbon $processed_at
 * @property ?string $payout_reference
 * @property ?string $reject_reason
 * @property ?string $hold_reason
 * @property ?Carbon $last_reminded_at
 * @property User $user
 */
class PayoutRequest extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['account_number', 'account_fingerprint'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'account_number' => 'encrypted', 'due_at' => 'datetime', 'processed_at' => 'datetime', 'last_reminded_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function maskedAccount(): string
    {
        $n = (string) $this->account_number;

        return str_repeat('•', max(0, strlen($n) - 4)).substr($n, -4);
    }
}
