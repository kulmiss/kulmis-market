<?php

namespace App\Notify;

use App\Jobs\SendCustomerMessage;
use App\Models\MembershipPurchase;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Payable;

/** Staff could not accept a payment receipt: tell the customer why, and that they can send a new one. */
final class ReceiptRejectedNotifier
{
    public static function send(Payment $payment): void
    {
        $payable = $payment->getRelationValue('payable');
        if (! $payable instanceof Payable) {
            return;
        }
        $c = $payable->payableCustomer();
        $what = $payable instanceof Order ? 'order '.$payable->number : mb_strtolower($payable->payableTitle());
        $where = match (true) {
            $payable instanceof Order => 'the payment page of your order (or /track)',
            $payable instanceof MembershipPurchase => 'your account page',
            default => 'the page where you paid',
        };
        $body = "We could not accept your payment receipt for $what. Reason: {$payment->reject_reason}. "
            ."Please send a new receipt on $where.";
        $userId = $payable instanceof Order ? $payable->user_id : ($payable instanceof MembershipPurchase ? $payable->user_id : null);
        $user = $userId ? User::query()->find($userId) : null;
        if ($user) {
            Inbox::add($user, $payable instanceof Order ? 'order' : 'renewal', 'Receipt not accepted: '.$payment->reject_reason, null,
                $payable instanceof Order ? '/pay/'.$payable->number : '/account#membership', 'receipt-rejected:'.$payment->id);
        }
        SendCustomerMessage::dispatch(new Message(
            subject: 'Receipt not accepted',
            body: $body,
            phone: $c['phone'],
            email: $c['email'],
            telegramChatId: $user?->telegram_user_id ? (int) $user->telegram_user_id : null,
            type: 'receipt_rejected',
        ));
    }
}
