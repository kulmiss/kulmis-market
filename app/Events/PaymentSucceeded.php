<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired once per payment, after commit. Referral commissions listen here. */
class PaymentSucceeded
{
    use Dispatchable;

    public function __construct(public Payment $payment) {}
}
