<?php

namespace App\Rewards;

use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;
use App\Fraud\FraudGuard;
use App\Membership\Members;
use App\Models\MembershipPurchase;
use App\Models\Payment;
use App\Models\User;
use App\Referral\Attribution;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Rewards paid from a membership payment:
 *  - first-payment bonus: to the person who invited them, only on the very
 *    first real payment of the new member (default 250 ETB);
 *  - welcome-back reward: to a member who comes back after N days away
 *    (default 0 = off).
 * Both scale with the real money paid (wallet money doesn't count), so a
 * half-wallet payment gives half the reward.
 */
class MembershipRewards
{
    public function __construct(private readonly Rewards $rewards, private readonly Attribution $attribution) {}

    public function paid(PaymentSucceeded $event): void
    {
        $payment = $event->payment;
        $purchase = $payment->payable;
        if (! $purchase instanceof MembershipPurchase || $payment->amount <= 0) {
            return;
        }
        $buyer = $purchase->user()->first();
        if (! $buyer instanceof User) {
            return;
        }
        $price = max(1, Settings::int('membership.price'));
        $real = min($payment->amount, $purchase->amount);
        $earlier = MembershipPurchase::query()->withoutGlobalScopes()->where('user_id', $buyer->id)->whereKeyNot($purchase->id)
            ->whereNotNull('paid_at')->where('amount', '>', 0);

        // First-payment bonus
        $bonus = Settings::int('bonus.first_payment');
        if (Features::enabled('referral') && Features::enabled('referral.first_payment_bonus') && $bonus > 0 && $buyer->referrer_id
            && ! (clone $earlier)->exists()) {
            $ref = User::query()->find($buyer->referrer_id);
            if ($ref && $this->canEarn($ref, $buyer, $payment)) {
                $amount = min($bonus, intdiv($bonus * $real, $price));
                $this->rewards->grant($ref, 'first_payment', $amount, $purchase, "first_payment:{$buyer->id}",
                    "First-payment bonus: {$buyer->first_name} paid for the first time", 'First-payment bonus');
            }
        }

        // Welcome-back reward
        $back = Settings::int('reward.comeback');
        if (Features::enabled('membership.comeback_reward') && $back > 0) {
            // They were a member before, and their latest paid time ended at least N days before this one starts.
            $start = $purchase->period_start ?? now();
            $last = (clone $earlier)->whereNotNull('period_end')->max('period_end');
            if ($last !== null && Carbon::parse($last)->lte($start->copy()->subDays(max(1, Settings::int('reward.comeback_days'))))) {
                $amount = min($back, intdiv($back * $real, $price));
                $this->rewards->grant($buyer, 'comeback', $amount, $purchase, "comeback:{$purchase->id}",
                    'Welcome back! A thank-you for coming back', 'Welcome-back reward');
            }
        }
    }

    public function refunded(PaymentRefunded $event): void
    {
        $payment = $event->payment;
        if ($payment->payable instanceof MembershipPurchase) {
            $this->rewards->reverseFor($payment->payable, (int) $payment->refunded_amount, (int) $payment->amount, (string) $event->refund->id);
        }
    }

    /** Same checks as team earnings: real active member, not the same person, not paying from their own account. */
    private function canEarn(User $ref, User $buyer, Payment $payment): bool
    {
        if (! $ref->isActiveAccount() || ! Members::isActive($ref)) {
            return false;
        }
        if ($this->attribution->isSamePerson($ref, $buyer, $buyer->phone, $buyer->email)) {
            return false;
        }

        return ! app(FraudGuard::class)->paidWithOwnAccount($ref, $payment->payer_account);
    }
}
