<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $method
 * @property string $account_name
 * @property string $account_number
 * @property string $account_fingerprint
 * @property ?string $bank_name
 * @property string $status
 * @property Carbon $usable_after
 * @property ?Carbon $verified_at
 * @property ?int $verified_by
 * @property ?Carbon $created_at
 */
class PayoutAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['account_number', 'account_fingerprint'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'usable_after' => 'datetime', 'verified_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->usable_after->isPast();
    }

    public function masked(): string
    {
        $n = (string) $this->account_number;

        return str_repeat('•', max(0, strlen($n) - 4)).substr($n, -4);
    }

    /** @return array<string, mixed> what the member sees */
    public function present(): array
    {
        return ['method' => $this->method, 'account_name' => $this->account_name, 'account' => $this->masked(), 'bank_name' => $this->bank_name,
            'usable_after' => $this->usable_after->toIso8601String(), 'usable' => $this->isUsable(), 'verified' => $this->verified_at !== null];
    }
}
