<?php

namespace App\Events;

use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired per refund, after commit. Commissions are reversed here. */
class PaymentRefunded
{
    use Dispatchable;

    public function __construct(public Payment $payment, public Refund $refund) {}
}
