<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Membership\Members;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ?int $user_id
 * @property string $source
 * @property string $first_name
 * @property string $last_name
 * @property ?string $phone
 * @property ?string $email
 * @property string $status
 * @property ?Carbon $current_period_end
 * @property ?Carbon $grace_ends_at
 * @property string $link_token
 * @property ?int $telegram_user_id
 * @property ?string $invite_link
 * @property ?Carbon $joined_at
 * @property ?Carbon $removed_at
 * @property ?User $user
 */
class SignalSubscription extends Model
{
    use HidesDemo;

    public const PURCHASE = 'purchase';

    public const MEMBERSHIP = 'membership';

    public const MANUAL = 'manual';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['current_period_end' => 'datetime', 'grace_ends_at' => 'datetime', 'joined_at' => 'datetime',
            'removed_at' => 'datetime', 'telegram_user_id' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SignalPurchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(SignalPurchase::class);
    }

    /** Paid (or manual) access until grace ends, or an active membership. */
    public function isEntitled(?Carbon $at = null): bool
    {
        $at ??= now();
        if ($this->source === self::MEMBERSHIP) {
            return Members::isActive($this->user);
        }

        return $this->grace_ends_at !== null && $this->grace_ends_at->gte($at);
    }

    public function deepLink(): ?string
    {
        $bot = (string) config('services.telegram.bot_username');

        return $bot !== '' ? 'https://t.me/'.ltrim($bot, '@').'?start='.$this->link_token : null;
    }
}
