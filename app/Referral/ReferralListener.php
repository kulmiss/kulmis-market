<?php

namespace App\Referral;

use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;

class ReferralListener
{
    public function __construct(private readonly CommissionEngine $engine) {}

    public function paid(PaymentSucceeded $event): void
    {
        $this->engine->forPayment($event->payment);
    }

    public function refunded(PaymentRefunded $event): void
    {
        $this->engine->reverseForRefund($event->payment, $event->refund->amount, $event->refund->id);
    }
}
