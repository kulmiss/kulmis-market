<?php

namespace App\Events;

use App\Models\Membership;
use App\Models\MembershipPurchase;
use Illuminate\Foundation\Events\Dispatchable;

class MembershipActivated
{
    use Dispatchable;

    public function __construct(public Membership $membership, public MembershipPurchase $purchase) {}
}
