<?php

namespace App\Membership;

use App\Models\User;
use App\Support\Features;

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
}
