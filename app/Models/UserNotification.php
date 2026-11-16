<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Notify\Inbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * In-app notification shown in the member area (badge unlocked, …).
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property string|null $url
 * @property array<string, mixed>|null $data
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
class UserNotification extends Model
{
    use HidesDemo;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * Only news whose feature is on (old news of a switched-off feature stays stored, just not shown).
     *
     * @param  Builder<UserNotification>  $query
     */
    public function scopeShown(Builder $query): void
    {
        $hidden = Inbox::hiddenTypes();
        if ($hidden !== []) {
            $query->whereNotIn('type', $hidden);
        }
    }

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }
}
