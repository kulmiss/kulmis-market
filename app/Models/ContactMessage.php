<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A message from the help centre contact form.
 *
 * @property int $id
 * @property ?int $user_id
 * @property string $name
 * @property string $contact
 * @property ?string $topic
 * @property string $message
 * @property string $status
 * @property ?Carbon $answered_at
 * @property ?Carbon $created_at
 */
class ContactMessage extends Model
{
    public const TOPICS = ['order' => 'My order', 'membership' => 'Membership', 'earnings' => 'Earnings and cash-out',
        'signals' => 'Signals', 'other' => 'Something else'];

    public const STATUSES = ['new', 'answered', 'spam'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }
}
