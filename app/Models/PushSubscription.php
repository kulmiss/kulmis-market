<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One phone or browser that said yes to notifications.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint_hash
 * @property string $endpoint
 * @property ?string $device
 * @property ?Carbon $last_used_at
 * @property int $failures
 */
class PushSubscription extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['endpoint'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'failures' => 'integer'];
    }
}
