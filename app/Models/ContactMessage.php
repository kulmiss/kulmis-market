<?php

namespace App\Models;

use App\Support\Features;
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

    /** The topic needs this feature to be offered. */
    private const TOPIC_FEATURES = ['order' => 'shop', 'membership' => 'membership', 'earnings' => 'referral', 'signals' => 'signals'];

    /** @return array<string, string> topics of features that are on */
    public static function topics(): array
    {
        $topics = array_filter(self::TOPICS, fn (string $k) => Features::enabled(self::TOPIC_FEATURES[$k] ?? 'site.help_centre'), ARRAY_FILTER_USE_KEY);
        if (isset($topics['earnings']) && Features::disabled('wallet.cashout')) {
            $topics['earnings'] = 'Earnings';
        }

        return $topics;
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }
}
