<?php

namespace App\Signals;

use App\Events\MembershipActivated;
use App\Events\MembershipEnded;
use App\Models\SignalSubscription;
use App\Support\Features;

/** Signals are included for active members. */
class MembershipSignalsListener
{
    public function __construct(private readonly SignalAccess $access) {}

    public function activated(MembershipActivated $event): void
    {
        if (Features::disabled('signals')) {
            return;
        }
        $user = $event->membership->user()->firstOrFail();
        $sub = $this->access->grantMember($user);
        if ($sub->joined_at === null || $sub->removed_at !== null || $sub->wasRecentlyCreated) {
            $this->access->sendAccessLink($sub);
        }
    }

    public function ended(MembershipEnded $event): void
    {
        SignalSubscription::query()->with('user.membership')->where('user_id', $event->membership->user_id)
            ->where('source', SignalSubscription::MEMBERSHIP)->where('status', '!=', 'ended')
            ->each(fn (SignalSubscription $s) => $this->access->removeIfNotEntitled($s));
    }
}
