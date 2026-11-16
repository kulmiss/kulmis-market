<?php

namespace App\Member;

use App\Badges\BadgeEngine;
use App\Badges\BadgeRules;
use App\Badges\BonusCard;
use App\Ledger\Wallet;
use App\Membership\Members;
use App\Models\Commission;
use App\Models\Membership;
use App\Models\MembershipPurchase;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\UserBadge;
use App\Referral\TeamView;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Everything the member home screen shows, in one place. Same privacy rules
 * as TeamView: level 1 first name + last initial, levels 2–3 counts only.
 */
class MemberDashboard
{
    private const EARNED = [Commission::PENDING, Commission::AVAILABLE, Commission::REVERSED];

    /** @return array<string, mixed> */
    public function for(User $user, string $chart = 'daily'): array
    {
        $referral = Features::enabled('referral') ? app(TeamView::class)->for($user) : null;

        return [
            'money' => $this->money($user),
            'chart' => $chart === 'monthly' ? $this->monthly($user) : $this->daily($user),
            'chart_mode' => $chart === 'monthly' ? 'monthly' : 'daily',
            'by_level' => $this->byLevel($user),
            'by_type' => $this->byType($user),
            'referral' => $referral,
            'newest' => $referral ? $this->newest($user) : [],
            'membership' => $this->membership($user),
            'badges' => Features::enabled('badges') ? app(BadgeEngine::class)->summary($user) : null,
            'bonus' => app(BonusCard::class)->for($user),
            'activity' => $this->activity($user),
            'is_new' => $this->isNew($user),
        ];
    }

    /** @return array{earned:int, pending:int, available:int, paid_out:int, requested:int, this_month:int, last_month:int, change:?int} */
    public function money(User $user): array
    {
        $w = Wallet::for($user)->summary();
        [$all, $this1, $last] = $this->earnedIn($user, [
            [now()->startOfMonth(), now()],
            [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
        ]);

        return [
            'earned' => $all,
            'pending' => $w['pending'],
            'available' => $w['available'],
            'requested' => $w['payout'],
            'paid_out' => (int) PayoutRequest::query()->where('user_id', $user->id)->where('status', 'paid')->sum('amount'),
            'this_month' => $this1,
            'last_month' => $last,
            'change' => $last > 0 ? (int) round(($this1 - $last) * 100 / $last) : null,
        ];
    }

    /**
     * Everything a member earned (after money taken back): team earnings,
     * first-payment bonuses, prizes and rewards, badge bonus and loyalty —
     * the same money that lands in the wallet, so the numbers agree.
     */
    public function earned(User $user, ?Carbon $from = null, ?Carbon $to = null): int
    {
        return $from ? $this->earnedIn($user, [[$from, $to ?? now()]])[1] : $this->earnedIn($user, [])[0];
    }

    /**
     * One query: [all time, then one total per [from, to] range].
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $ranges
     * @return list<int>
     */
    private function earnedIn(User $user, array $ranges): array
    {
        $rows = \DB::query()->fromSub(
            Commission::query()->where('recipient_id', $user->id)->whereIn('status', self::EARNED)->selectRaw('created_at, amount - reversed_amount AS v')
                ->unionAll(\DB::table('rewards')->where('user_id', $user->id)->selectRaw('created_at, amount - reversed_amount AS v'))
                ->unionAll(\DB::table('badge_bonuses')->where('user_id', $user->id)->selectRaw('created_at, amount - reduced_amount AS v'))
                ->unionAll(\DB::table('loyalty_rewards')->where('user_id', $user->id)->selectRaw('created_at, amount AS v')),
            'e');
        $select = ['COALESCE(SUM(v), 0) AS t0'];
        $bindings = [];
        foreach ($ranges as $i => [$from, $to]) {
            $select[] = 'COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN v ELSE 0 END), 0) AS t'.($i + 1);
            array_push($bindings, $from->toDateTimeString(), $to->toDateTimeString());
        }
        $row = (array) $rows->selectRaw(implode(', ', $select), $bindings)->first();

        return array_map(fn ($k) => (int) ($row['t'.$k] ?? 0), range(0, count($ranges)));
    }

    /** @return list<array{label:string, value:int}> last 30 days */
    public function daily(User $user): array
    {
        $from = now()->subDays(29)->startOfDay();
        $rows = Commission::query()->where('recipient_id', $user->id)->whereIn('status', self::EARNED)->where('created_at', '>=', $from)
            ->get(['created_at', 'amount', 'reversed_amount'])
            ->groupBy(fn (Commission $c) => $c->created_at->timezone(config('app.timezone'))->toDateString())
            ->map(fn ($g) => $g->sum(fn (Commission $c) => $c->amount - $c->reversed_amount));
        $out = [];
        for ($d = $from->copy(); $d->lte(now()); $d->addDay()) {
            $k = $d->copy()->timezone(config('app.timezone'))->toDateString();
            $out[] = ['label' => $d->format('j M'), 'value' => (int) ($rows[$k] ?? 0)];
        }

        return $out;
    }

    /** @return list<array{label:string, value:int}> last 12 months */
    public function monthly(User $user): array
    {
        $from = now()->subMonthsNoOverflow(11)->startOfMonth();
        $rows = Commission::query()->where('recipient_id', $user->id)->whereIn('status', self::EARNED)->where('created_at', '>=', $from)
            ->get(['created_at', 'amount', 'reversed_amount'])
            ->groupBy(fn (Commission $c) => $c->created_at->format('Y-m'))
            ->map(fn ($g) => $g->sum(fn (Commission $c) => $c->amount - $c->reversed_amount));
        $out = [];
        for ($m = $from->copy(); $m->lte(now()); $m->addMonthNoOverflow()) {
            $out[] = ['label' => $m->format('M'), 'value' => (int) ($rows[$m->format('Y-m')] ?? 0)];
        }

        return $out;
    }

    /** @return array<int, int> level => net earned */
    public function byLevel(User $user): array
    {
        $rows = Commission::query()->where('recipient_id', $user->id)->whereIn('status', self::EARNED)
            ->selectRaw('level, SUM(amount - reversed_amount) as t')->groupBy('level')->pluck('t', 'level');

        return [1 => (int) ($rows[1] ?? 0), 2 => (int) ($rows[2] ?? 0), 3 => (int) ($rows[3] ?? 0)];
    }

    /** @return array<string, int> type => net earned */
    public function byType(User $user): array
    {
        $rows = Commission::query()->where('recipient_id', $user->id)->whereIn('status', self::EARNED)
            ->selectRaw('type, SUM(amount - reversed_amount) as t')->groupBy('type')->pluck('t', 'type');

        return ['membership' => (int) ($rows['membership'] ?? 0), 'products' => (int) ($rows['products'] ?? 0), 'signals' => (int) ($rows['signals'] ?? 0)];
    }

    /** @return list<array{name:string, level:int, joined:string, active:bool}> */
    public function newest(User $user, int $limit = 6): array
    {
        $l1 = User::query()->with('membership')->where('referrer_id', $user->id)->latest('created_at')->limit($limit)->get();
        $l1Ids = User::query()->where('referrer_id', $user->id)->pluck('id');
        $l2 = User::query()->with('membership')->whereIn('referrer_id', $l1Ids)->latest('created_at')->limit($limit)->get();
        $l3 = User::query()->with('membership')->whereIn('referrer_id', User::query()->whereIn('referrer_id', $l1Ids)->select('id'))->latest('created_at')->limit($limit)->get();
        $rows = [];
        foreach ([1 => $l1, 2 => $l2, 3 => $l3] as $level => $people) {
            foreach ($people as $u) {
                $rows[] = ['name' => $level === 1 ? $u->publicName() : 'New member', 'level' => $level,
                    'joined' => $u->created_at, 'active' => Members::isActive($u)];
            }
        }
        usort($rows, fn ($a, $b) => $b['joined'] <=> $a['joined']);

        return array_map(fn ($r) => ['joined' => $r['joined']->timezone(config('app.timezone'))->format('j M')] + $r, array_slice($rows, 0, $limit));
    }

    /** @return array{status:string, active:bool, ends:?Carbon, grace_until:?Carbon, streak_months:int, next_reward_in:?int, reward:int, price:int} */
    public function membership(User $user): array
    {
        $m = Membership::query()->where('user_id', $user->id)->first();
        $active = Members::isActive($user);
        $streak = $active && $m?->continuous_since ? (int) floor($m->continuous_since->diffInMonths(now())) : 0;
        $every = max(1, Settings::int('loyalty.every_months'));

        return [
            'status' => $m === null ? 'none' : ($active ? $m->status : 'expired'),
            'active' => $active,
            'ends' => $m?->current_period_end,
            'grace_until' => $m?->status === 'grace' ? $m->grace_ends_at : null,
            'streak_months' => $streak,
            'next_reward_in' => $active && Features::enabled('loyalty_reward') ? $every - ($streak % $every) : null,
            'reward' => Settings::int('loyalty.reward'),
            'price' => Settings::int('membership.price'),
        ];
    }

    /** @return list<array{when:?CarbonInterface, icon:string, text:string, amount:?int, tone:string}> */
    public function activity(User $user, int $limit = 8): array
    {
        $items = [];
        // Past money stays, with neutral words when its feature is off now.
        foreach (Commission::query()->where('recipient_id', $user->id)->whereIn('status', self::EARNED)->latest('id')->limit($limit)->get() as $c) {
            $neutral = Features::disabled('referral') || MemberTables::earningType($c->type) === 'Earlier earning';
            $items[] = ['when' => $c->created_at, 'icon' => 'coins', 'tone' => 'accent', 'amount' => $c->amount - $c->reversed_amount,
                'text' => ($neutral ? 'Earlier earning' : 'Earned from level '.$c->level.' ('.$c->type.')').($c->status === Commission::PENDING ? ' · pending' : '')];
        }
        foreach (PayoutRequest::query()->where('user_id', $user->id)->latest('id')->limit(4)->get() as $p) {
            $items[] = ['when' => $p->created_at, 'icon' => 'handshake', 'tone' => $p->status === 'rejected' ? 'warn' : 'neutral', 'amount' => -$p->amount,
                'text' => (Features::enabled('wallet.cashout') ? 'Cash-out ' : 'Money sent out · ').($p->status === 'on_hold' ? 'requested' : $p->status)];
        }
        foreach (Features::enabled('badges') ? UserBadge::query()->with('badge')->where('user_id', $user->id)
            ->whereHas('badge', fn ($q) => $q->whereIn('rule', BadgeRules::onNow()))->latest('id')->limit(3)->get() : [] as $b) {
            $items[] = ['when' => $b->unlocked_at, 'icon' => $b->badge->icon, 'tone' => 'gold', 'amount' => null, 'text' => 'Unlocked the '.$b->badge->name.' badge'];
        }
        foreach (Features::enabled('shop') ? Order::query()->where('user_id', $user->id)->latest('id')->limit(3)->get() : [] as $o) {
            $items[] = ['when' => $o->created_at, 'icon' => 'shopping-bag', 'tone' => 'neutral', 'amount' => null,
                'text' => 'Order '.$o->number.' · '.str_replace('_', ' ', $o->status).' · '.Money::format($o->total)];
        }
        foreach (Features::enabled('membership') ? MembershipPurchase::query()->where('user_id', $user->id)->where('status', 'paid')->latest('id')->limit(2)->get() : [] as $mp) {
            $items[] = ['when' => $mp->paid_at ?? $mp->created_at, 'icon' => 'calendar-check', 'tone' => 'neutral', 'amount' => null, 'text' => 'Membership paid'];
        }
        usort($items, fn ($a, $b) => $b['when'] <=> $a['when']);

        return array_slice($items, 0, $limit);
    }

    private function isNew(User $user): bool
    {
        return ! Commission::query()->where('recipient_id', $user->id)->exists() && ! User::query()->where('referrer_id', $user->id)->exists();
    }
}
