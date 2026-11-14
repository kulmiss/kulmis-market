<?php

namespace App\Events;

use App\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;

/** Access is over (after the grace period, or revoked by refund/admin). */
class MembershipEnded
{
    use Dispatchable;

    public function __construct(public Membership $membership, public string $reason) {}
}
