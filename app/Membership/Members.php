<?php

namespace App\Membership;

use App\Models\MembershipPurchase;
use App\Models\User;
use App\Support\Features;
use Illuminate\Support\Carbon;

/** "Is this person an active paying member right now?" — one place for it. */
class Members
{
    public static function isActive(?User $user): bool
    {
        if ($user === null || Features::disabled('membership')) {
            return false;
        }
        $membership = $user->relationLoaded('membership') ? $user->membership : $user->membership()->first();

        return (bool) $membership?->isActive();
    }

    /**
     * Was this person a member at that moment? (Used after a contest or a
     * challenge month, when today's status may already have moved on.)
     */
    public static function wasActiveAt(User $user, Carbon $at): bool
    {
        if (self::isActive($user)) {
            return true;
        }
        $m = $user->relationLoaded('membership') ? $user->membership : $user->membership()->first();
        $end = $m?->accessEndsAt();
        if ($m && $m->current_period_start && $m->current_period_start->lte($at) && $end && $end->gte($at)) {
            return true;
        }

        return MembershipPurchase::query()->where('user_id', $user->id)->where('status', 'paid')
            ->where('period_start', '<=', $at)->where('period_end', '>=', $at)->exists();
    }
}
