<?php

namespace App\Shop;

use App\Jobs\SendCustomerMessage;
use App\Models\CustomRequest;
use App\Models\Order;
use App\Models\User;
use App\Notify\Inbox;
use App\Notify\Message;
use App\Orders\Checkout;
use App\Orders\OrderService;
use App\Support\Activity;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pay part now, the rest before shipping: pre-orders (a share of the
 * pre-order items) and custom orders (a share of the quote). Team earnings
 * wait until the whole order is paid.
 */
class Deposits
{
    /** Deposit for an order with pre-order lines: other items in full, pre-order items at their share. */
    public static function forPreorder(Order $order): int
    {
        $later = 0;
        foreach ($order->items as $item) {
            if ($item->deposit_bps !== null) {
                $later += intdiv($item->line_total * (10_000 - (int) $item->deposit_bps), 10_000);
            }
        }

        return $later > 0 ? max(100, $order->total - $later) : 0;
    }

    public function depositReceived(Order $order): void
    {
        SendCustomerMessage::dispatch(new Message("Order {$order->number}: deposit received", 'Thank you! We got your deposit of '.Money::format($order->deposit_paid)
            .'. We will tell you when your order is ready; then you pay the rest ('.Money::format($order->amountDue()).') before we send it.', $order->phone, $order->email, type: 'order_paid'));
        if ($order->user_id && ($u = User::query()->find($order->user_id))) {
            Inbox::add($u, 'order', "Order {$order->number}: deposit received", 'The rest is due when it is ready.', '/account/orders', "deposit:{$order->id}");
        }
    }

    /** Admin: "it's ready — please pay the rest". */
    public function askForTheRest(Order $order): void
    {
        if (! $order->depositStage()) {
            throw new InvalidArgumentException('Nothing left to ask for.');
        }
        $link = url('/pay/'.$order->number.'?phone='.urlencode($order->phone));
        SendCustomerMessage::dispatch(new Message("Order {$order->number} is ready", 'Good news: your order is ready! Please pay the rest ('
            .Money::format($order->amountDue()).") and we send it: $link", $order->phone, $order->email, type: 'order_paid'));
        Activity::log('order.rest_requested', $order);
    }

    /** Admin quotes a custom request: this makes the order (waiting for the deposit). */
    public function quote(CustomRequest $req, int $price, int $cost, int $makingDays, int $depositBps, ?string $note, int $actorId): Order
    {
        if ($req->status !== 'new' && $req->status !== 'quoted') {
            throw new InvalidArgumentException('This request is already ordered or declined.');
        }

        return DB::transaction(function () use ($req, $price, $cost, $makingDays, $depositBps, $note, $actorId) {
            if ($req->order_id) {
                $old = Order::query()->find($req->order_id);
                if ($old && $old->status === Order::PENDING_PAYMENT && $old->deposit_paid === 0) {
                    app(OrderService::class)->cancel($old, 'New quote', $actorId);
                }
            }
            $order = Order::query()->create([
                'number' => app(Checkout::class)->newNumber(), 'user_id' => $req->user_id, 'first_name' => $req->name, 'last_name' => '',
                'phone' => $req->phone, 'email' => $req->email, 'city' => $req->city, 'address' => $req->address, 'notes' => $note,
                'status' => Order::PENDING_PAYMENT, 'is_member_order' => false, 'list_subtotal' => $price, 'member_discount' => 0,
                'coupon_discount' => 0, 'merchandise_total' => $price, 'shipping' => 0, 'shipping_discount' => 0, 'total' => $price,
                'deposit_amount' => max(100, intdiv($price * min(10_000, max(1_000, $depositBps)), 10_000)), 'checkout_key' => 'custom-'.$req->id.'-'.uniqid(),
            ]);
            $order->items()->create(['product_id' => null, 'name' => 'Custom: '.mb_substr($req->description, 0, 80), 'quantity' => 1,
                'list_unit_price' => $price, 'unit_price' => $price, 'unit_cost' => $cost, 'line_total' => $price,
                'made_to_order' => true, 'making_days' => $makingDays]);
            $order->history()->create(['from' => null, 'to' => Order::PENDING_PAYMENT, 'note' => 'Custom order quoted', 'actor_id' => $actorId]);
            $req->update(['status' => 'quoted', 'order_id' => $order->id]);
            $link = url('/pay/'.$order->number.'?phone='.urlencode($order->phone));
            DB::afterCommit(fn () => SendCustomerMessage::dispatch(new Message('Your custom order quote', "Hi {$req->name}! Your custom piece: "
                .Money::format($price).", ready in about $makingDays days.".($note ? " $note" : '').' To start, pay the deposit of '
                .Money::format($order->deposit_amount).": $link", $req->phone, $req->email, type: 'order_paid')));
            Activity::log('custom.quoted', $req, ['order' => $order->number, 'price' => Money::format($price)], null, $actorId);

            return $order;
        });
    }

    /** Quote valid days (unpaid custom quotes are cancelled after this). */
    public static function quoteDays(): int
    {
        return max(1, Settings::int('custom.quote_days'));
    }
}
