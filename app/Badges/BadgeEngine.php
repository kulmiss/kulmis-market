<?php

namespace App\Badges;

use App\Jobs\SendCustomerMessage;
use App\Membership\Members;
use App\Models\Badge;
use App\Models\Commission;
use App\Models\Membership;
use App\Models\Order;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\UserNotification;
use App\Notify\Message;
use App\Referral\Engagement;
use App\Support\Activity;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out every badge's progress ("3/10 to Silver") and unlocks badges.
 * A badge, once unlocked, is kept.
 */
class BadgeEngine
{
    /** @var array<string, int> metric cache for the user being evaluated */
    private array $metrics = [];

    private ?int $metricsFor = null;

    /** @return Collection<int, Badge> */
    public function activeBadges(): Collection
    {
        return Badge::query()->where('is_active', true)->orderBy('rule')->orderBy('threshold')->get();
    }

    public function metric(User $user, string $rule): int
    {
        if ($this->metricsFor !== $user->id) {
            $this->metrics = [];
            $this->metricsFor = $user->id;
        }

        return $this->metrics[$rule] ??= match ($rule) {
            'active_level1' => app(Engagement::class)->activeLevel1($user),
            'total_team' => $this->activeTeam($user),
            'loyalty_months' => $this->loyaltyMonths($user),
            'link_sales' => $this->linkSales($user),
            'earnings_total' => (int) Commission::query()->where('recipient_id', $user->id)
                ->whereIn('status', [Commission::PENDING, Commission::AVAILABLE])->selectRaw('COALESCE(SUM(amount - reversed_amount), 0) as t')->value('t'),
            'top_referrer_month' => 0, // awarded by the monthly job (badges:top-referrer)
            'early_member' => $this->memberNumber($user),
            'orders_count' => Order::query()->where('user_id', $user->id)->whereNotNull('paid_at')
                ->whereNotIn('status', [Order::CANCELLED, Order::REFUNDED])->count(),
            default => 0,
        };
    }

    public function qualifies(User $user, Badge $badge): bool
    {
        if ($badge->rule === 'top_referrer_month') {
            return false;
        }
        $value = $this->metric($user, $badge->rule);

        return BadgeRules::lowerIsBetter($badge->rule) ? ($value > 0 && $value <= $badge->threshold) : $value >= $badge->threshold;
    }

    /**
     * Unlock every badge the member now qualifies for.
     *
     * @return list<Badge> newly unlocked
     */
    public function evaluate(User $user): array
    {
        if (Features::disabled('badges') || $user->isStaff()) {
            return [];
        }
        $this->metricsFor = null;
        $have = UserBadge::query()->where('user_id', $user->id)->pluck('badge_id')->all();
        $new = [];
        foreach ($this->activeBadges() as $badge) {
            if (! in_array($badge->id, $have, true) && $this->qualifies($user, $badge) && $this->unlock($user, $badge)) {
                $new[] = $badge;
            }
        }

        return $new;
    }

    public function unlock(User $user, Badge $badge): bool
    {
        try {
            UserBadge::query()->create(['user_id' => $user->id, 'badge_id' => $badge->id, 'unlocked_at' => now()]);
        } catch (QueryException) {
            return false; // unlocked at the same moment by another job
        }
        Activity::log('badge.unlocked', $user, ['badge' => $badge->key], null, $user->id);
        UserNotification::query()->create(['user_id' => $user->id, 'type' => 'badge_unlocked', 'title' => 'New badge: '.$badge->name,
            'body' => $badge->description, 'url' => '/account/badges', 'data' => ['badge_id' => $badge->id]]);
        if (Features::enabled('badges.messages')) {
            $text = strtr(Settings::string('notify.badge_unlocked'), ['{name}' => $user->first_name, '{badge}' => $badge->name,
                '{site}' => Settings::string('site.name'), '{url}' => url('/account/badges')]);
            SendCustomerMessage::dispatch(new Message('New badge: '.$badge->name, $text, $user->phone, $user->email, $user->telegram_user_id));
        }

        return true;
    }

    /**
     * Every active badge with progress, for the badges page and the API.
     *
     * @return list<array{id:int, key:string, name:string, description:string, icon:string, color:string, rule:string, rank:int,
     *   threshold:int, value:int, percent:int, unlocked:bool, unlocked_at:?string, label:string}>
     */
    public function progress(User $user): array
    {
        $mine = UserBadge::query()->where('user_id', $user->id)->get()->keyBy('badge_id');
        $out = [];
        foreach ($this->activeBadges() as $badge) {
            $held = $mine->get($badge->id);
            $value = $this->metric($user, $badge->rule);
            $out[] = [
                'id' => $badge->id, 'key' => $badge->key, 'name' => $badge->name, 'description' => $badge->description,
                'icon' => $badge->icon, 'color' => $badge->color, 'rule' => $badge->rule, 'rank' => $badge->rank,
                'threshold' => $badge->threshold, 'value' => $value,
                'percent' => $held ? 100 : $this->percent($badge, $value),
                'unlocked' => $held !== null, 'unlocked_at' => $held?->unlocked_at->toIso8601String(),
                'label' => $held ? 'Unlocked '.$held->unlocked_at->timezone(config('app.timezone'))->format('d M Y') : $this->label($badge, $value),
            ];
        }

        return $out;
    }

    /** Highest unlocked badge (by rank), e.g. for the top referrers list. */
    public function highest(User $user): ?Badge
    {
        if (Features::disabled('badges')) {
            return null;
        }

        return Badge::query()->where('is_active', true)
            ->whereIn('id', UserBadge::query()->where('user_id', $user->id)->select('badge_id'))
            ->orderByDesc('rank')->first();
    }

    /**
     * Summary for the dashboard: highest badge, the closest next badge, unseen unlocks.
     *
     * @return array{badge:?string, highest:?array<string,mixed>, next:?array<string,mixed>, unseen:list<array<string,mixed>>, unlocked:int, total:int, active_level1:int}
     */
    public function summary(User $user): array
    {
        if (Features::disabled('badges')) {
            return ['badge' => null, 'highest' => null, 'next' => null, 'unseen' => [], 'unlocked' => 0, 'total' => 0, 'active_level1' => 0];
        }
        $all = collect($this->progress($user));
        $highest = $all->where('unlocked', true)->sortByDesc('rank')->first();
        $next = $all->where('unlocked', false)->reject(fn ($b) => $b['rule'] === 'top_referrer_month' || ($b['rule'] === 'early_member' && $b['percent'] === 0))
            ->sortBy([['percent', 'desc'], ['rank', 'asc']])->first();
        $unseenIds = UserBadge::query()->where('user_id', $user->id)->whereNull('seen_at')->pluck('badge_id')->all();

        return [
            'badge' => $highest['name'] ?? null,
            'highest' => $highest,
            'next' => $next,
            'unseen' => $all->whereIn('id', $unseenIds)->values()->all(),
            'unlocked' => $all->where('unlocked', true)->count(),
            'total' => $all->count(),
            'active_level1' => $this->metric($user, 'active_level1'),
        ];
    }

    public function markSeen(User $user): void
    {
        UserBadge::query()->where('user_id', $user->id)->whereNull('seen_at')->update(['seen_at' => now()]);
    }

    /** Monthly: the top referrers of $month get the "top referrer" badges their rank qualifies for. */
    public function awardTopReferrers(Carbon $month): int
    {
        if (Features::disabled('badges')) {
            return 0;
        }
        $badges = Badge::query()->where('is_active', true)->where('rule', 'top_referrer_month')->get();
        if ($badges->isEmpty()) {
            return 0;
        }
        $awarded = 0;
        foreach (app(Engagement::class)->ranking($month, (int) $badges->max('threshold')) as $i => $row) {
            foreach ($badges->where('threshold', '>=', $i + 1) as $badge) {
                $exists = UserBadge::query()->where('user_id', $row['user']->id)->where('badge_id', $badge->id)->exists();
                if (! $exists && $this->unlock($row['user'], $badge)) {
                    $awarded++;
                }
            }
        }

        return $awarded;
    }

    private function percent(Badge $badge, int $value): int
    {
        if (BadgeRules::lowerIsBetter($badge->rule)) {
            return 0;
        }

        return $badge->threshold > 0 ? (int) min(99, floor($value * 100 / $badge->threshold)) : 0;
    }

    private function label(Badge $badge, int $value): string
    {
        $hint = BadgeRules::RULES[$badge->rule]['hint'] ?? 'to';

        return match ($badge->rule) {
            'earnings_total' => Money::format($value).' / '.Money::format($badge->threshold).' '.$hint.' '.$badge->name,
            'top_referrer_month' => $hint.' '.($badge->threshold > 1 ? $badge->threshold : '1').' of a month',
            'early_member' => $value > 0 && $value <= $badge->threshold ? 'Member #'.$value : $hint.' '.$badge->threshold.' members (all taken)',
            default => $value.'/'.$badge->threshold.' '.$hint.' '.$badge->name,
        };
    }

    private function activeTeam(User $user): int
    {
        $ids = [$user->id];
        $count = 0;
        for ($level = 1; $level <= 3 && $ids; $level++) {
            $people = User::query()->with('membership')->whereIn('referrer_id', $ids)->get();
            $count += $people->filter(fn (User $u) => Members::isActive($u))->count();
            $ids = $people->pluck('id')->all();
        }

        return $count;
    }

    private function loyaltyMonths(User $user): int
    {
        $m = Membership::query()->where('user_id', $user->id)->first();

        return $m && $m->isActive() && $m->continuous_since ? (int) floor($m->continuous_since->diffInMonths(now())) : 0;
    }

    private function linkSales(User $user): int
    {
        return Order::query()->whereNotNull('paid_at')->whereNotIn('status', [Order::CANCELLED, Order::REFUNDED])
            ->where(fn ($q) => $q->where('referrer_user_id', $user->id)
                ->orWhereIn('user_id', User::query()->where('referrer_id', $user->id)->select('id')))
            ->count();
    }

    /** 1 = the first person who became a member. 0 = never a member. */
    private function memberNumber(User $user): int
    {
        $mine = Membership::query()->where('user_id', $user->id)->first();
        if (! $mine) {
            return 0;
        }

        return Membership::query()->where(fn ($q) => $q->where('created_at', '<', $mine->created_at)
            ->orWhere(fn ($q) => $q->where('created_at', $mine->created_at)->where('id', '<', $mine->id)))->count() + 1;
    }
}
