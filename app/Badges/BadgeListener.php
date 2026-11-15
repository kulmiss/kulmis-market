<?php

namespace App\Badges;

use App\Events\PaymentSucceeded;
use App\Jobs\EvaluateBadges;
use App\Models\Order;
use App\Models\User;
use App\Support\Features;

/** A payment can unlock badges for the buyer and for the 3 people above them (team, earnings, sales). */
class BadgeListener
{
    public function paid(PaymentSucceeded $event): void
    {
        if (Features::disabled('badges')) {
            return;
        }
        $payable = $event->payment->payable;
        $ids = [];
        $buyer = $event->payment->user_id ? User::query()->find($event->payment->user_id) : null;
        if ($buyer) {
            $ids[] = $buyer->id;
        }
        $up = $buyer !== null && $buyer->referrer_id ? $buyer->referrer_id : ($payable instanceof Order ? $payable->referrer_user_id : null);
        for ($level = 1; $level <= 3 && $up; $level++) {
            $ids[] = $up;
            $up = User::query()->whereKey($up)->value('referrer_id');
        }
        foreach (array_unique($ids) as $id) {
            EvaluateBadges::dispatch((int) $id);
        }
    }
}
