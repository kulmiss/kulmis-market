<?php

namespace App\Badges;

use App\Models\BadgeBonus;
use App\Models\User;
use App\Support\Features;

/** "Your badge bonus" on the member home page. */
final class BonusCard
{
    public function __construct(private readonly BonusCalculator $calculator) {}

    /** @return array<string, mixed>|null null when bonuses are off */
    public function for(User $user): ?array
    {
        if (Features::disabled('badges.bonus')) {
            return null;
        }
        $ladder = BonusCalculator::ladder();
        if ($ladder->isEmpty()) {
            return null;
        }
        $now = now();
        [$from] = BonusCalculator::bounds($now);
        $active = $this->calculator->activeLevel1At($user, $now);
        $current = $ladder->filter(fn ($b) => $active >= $b->threshold)->last();
        $next = $ladder->first(fn ($b) => $active < $b->threshold);
        $paidAllMonth = $this->calculator->paidThrough($user, $from, $now);

        $history = BadgeBonus::query()->with(['badge:id,name,color', 'run:id,adjusted'])->where('user_id', $user->id)
            ->latest('month')->latest('id')->limit(6)->get()
            ->map(fn (BadgeBonus $b) => [
                'month' => $b->month->format('F Y'),
                'badge' => $b->badge->name ?? '',
                'color' => $b->badge->color ?? null,
                'kind' => $b->kind,
                'amount' => $b->outstanding(),
                'status' => match (true) {
                    $b->status === BadgeBonus::AVAILABLE => 'Ready to use',
                    $b->status === BadgeBonus::REDUCED => 'Taken back (a membership was refunded)',
                    default => 'On the way · ready '.$b->available_at?->timezone(config('app.timezone'))->format('d M'),
                },
                'adjusted' => (bool) $b->run?->adjusted,
            ])->all();

        return [
            'active' => $active,
            'current' => $current ? ['name' => $current->name, 'color' => $current->color, 'icon' => $current->icon, 'bonus' => $current->monthly_bonus] : null,
            'next' => $next ? ['name' => $next->name, 'color' => $next->color, 'icon' => $next->icon, 'bonus' => $next->monthly_bonus,
                'threshold' => $next->threshold, 'missing' => $next->threshold - $active,
                'percent' => (int) min(100, floor($active * 100 / max(1, $next->threshold)))] : null,
            'paid_all_month' => $paidAllMonth,
            'expected' => $current && $paidAllMonth ? $current->monthly_bonus : 0,
            'month' => $from->format('F'),
            'pay_day' => $from->copy()->addMonthNoOverflow()->format('j F'),
            'history' => $history,
            'adjusted_last' => (bool) ($history[0]['adjusted'] ?? false),
        ];
    }
}
