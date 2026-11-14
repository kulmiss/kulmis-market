<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property ?string $from
 * @property string $to
 * @property ?string $note
 * @property ?array<string, mixed> $notified
 */
class OrderStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'order_status_history';

    protected $fillable = ['order_id', 'from', 'to', 'note', 'actor_id', 'notified'];

    protected function casts(): array
    {
        return ['notified' => 'array'];
    }
}
