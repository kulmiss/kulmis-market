<?php

namespace App\Referral;

use App\Ledger\Wallet;
use App\Membership\Members;
use App\Models\Commission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What a member may see about their team:
 *  - Level 1: first name + last initial, joined date, active / not active
 *  - Levels 2 and 3: counts only
 *  - Earnings: totals per level
 * Never phone, email, address, purchases or anyone else's wallet.
 */
class TeamView
{
    /** @return array<string, mixed> */
    public function for(User $member): array
    {
        app(ReferralCodes::class)->assign($member);
        $l1 = User::query()->with('membership')->where('referrer_id', $member->id)->orderByDesc('created_at')->get();
        $l2Ids = User::query()->whereIn('referrer_id', $l1->pluck('id'))->pluck('id');
        $l3Count = User::query()->whereIn('referrer_id', $l2Ids)->count();

        $earnings = Commission::query()->where('recipient_id', $member->id)->whereIn('status', [Commission::PENDING, Commission::AVAILABLE, Commission::REVERSED])
            ->selectRaw('level, SUM(amount - reversed_amount) as total')->groupBy('level')->pluck('total', 'level');

        return [
            'code' => $member->referral_code,
            'link' => url('/r/'.$member->referral_code),
            'can_change_code' => $member->referral_code_changed_at === null,
            'earning' => Members::isActive($member),
            'team' => [
                'level1' => $l1->map(fn (User $u) => [
                    'name' => $u->publicName(),
                    'joined' => $u->created_at?->toDateString(),
                    'active' => Members::isActive($u),
                ])->values(),
                'level1_count' => $l1->count(),
                'level1_active' => $l1->filter(fn (User $u) => Members::isActive($u))->count(),
                'level2_count' => $l2Ids->count(),
                'level3_count' => $l3Count,
            ],
            'earnings_by_level' => collect([1, 2, 3])->mapWithKeys(fn ($l) => [$l => (int) ($earnings[$l] ?? 0)]),
            'wallet' => Wallet::for($member)->summary(),
            'badge' => app(Engagement::class)->progress($member),
            'share' => app(Engagement::class)->shareLinks(url('/r/'.$member->referral_code), (string) $member->referral_code),
            'qr_url' => route('api.referral.qr'),
            'renews_at' => $member->membership()->first()?->current_period_end?->toIso8601String(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> earnings history without buyer details */
    public function history(User $member, int $limit = 50): Collection
    {
        return Commission::query()->where('recipient_id', $member->id)->where('status', '!=', Commission::SKIPPED)
            ->latest('id')->limit($limit)->get()
            ->map(fn (Commission $c) => ['type' => $c->type, 'level' => $c->level, 'amount' => $c->amount,
                'reversed' => $c->reversed_amount, 'status' => $c->status, 'available_at' => $c->available_at?->toIso8601String(),
                'created_at' => $c->created_at?->toIso8601String()]);
    }
}
