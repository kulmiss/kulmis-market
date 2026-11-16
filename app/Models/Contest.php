<?php

namespace App\Models;

use App\Demo\HidesDemo;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A referral contest with a fixed prize list for places 1…N.
 *
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property string $rule
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property list<int> $prizes
 * @property int $min_score
 * @property string $status
 * @property int $paid_total
 * @property bool $was_cut
 * @property ?Carbon $paid_at
 * @property ?list<array<string, mixed>> $result
 */
class Contest extends Model
{
    use HidesDemo;

    public const RULES = [
        'new_paying_members' => 'Most new paying members (people you invited who pay for the first time)',
        'shop_sales' => 'Most shop sales through your link (ETB)',
    ];

    protected $fillable = ['name', 'description', 'rule', 'starts_at', 'ends_at', 'prizes', 'min_score', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'min_score' => 1];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'prizes' => 'array', 'min_score' => 'integer',
            'paid_total' => 'integer', 'was_cut' => 'boolean', 'paid_at' => 'datetime', 'result' => 'array'];
    }

    public function pool(): int
    {
        return (int) array_sum($this->prizes ?? []);
    }

    /** When prizes are worked out: after the end + the refund window (14 days). */
    public function settlesAt(): Carbon
    {
        return $this->ends_at->copy()->addDays(Settings::int('referral.pending_days'));
    }

    public function phase(): string
    {
        $now = now();

        return match (true) {
            $this->status === 'cancelled' => 'cancelled',
            $this->status === 'paid' => 'paid',
            $this->starts_at->gt($now) => 'scheduled',
            $this->ends_at->gt($now) => 'running',
            default => 'checking', // ended: prizes after the refund window and fraud checks
        };
    }
}
