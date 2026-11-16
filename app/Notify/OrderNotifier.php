<?php

namespace App\Notify;

use App\Events\OrderStatusChanged;
use App\Jobs\SendCustomerMessage;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;

/** Tells the customer about paid → making → shipped → delivered (and cancelled). */
class OrderNotifier
{
    public const NOTIFY = [Order::PAID, Order::MAKING, Order::SHIPPED, Order::DELIVERED, Order::CANCELLED];

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        if (! in_array($event->change->to, self::NOTIFY, true)) {
            return;
        }
        $body = self::render(Settings::string('notify.order_'.$event->change->to), $order, $event->change->note);
        $owner = $order->user_id ? User::query()->find($order->user_id) : null;
        $telegram = $owner?->telegram_user_id;
        if ($owner) {
            Inbox::add($owner, 'order', "Order {$order->number}: ".self::word($event->change->to), null, '/account/orders', "order:{$event->change->id}");
        }

        SendCustomerMessage::dispatch(new Message(
            subject: "Order {$order->number}: ".str_replace('_', ' ', $event->change->to),
            body: $body,
            phone: $order->phone,
            email: $order->email,
            telegramChatId: $telegram ? (int) $telegram : null,
            type: 'order_'.$event->change->to,
        ), $event->change->id);
    }

    private static function word(string $status): string
    {
        return match ($status) {
            Order::PAID => 'paid, thank you', Order::MAKING => 'being made', Order::SHIPPED => 'on its way',
            Order::DELIVERED => 'delivered', Order::CANCELLED => 'cancelled', default => str_replace('_', ' ', $status),
        };
    }

    /** The tracking page, or (switched off) the account's orders / the home page. */
    public static function trackUrl(Order $order): string
    {
        if (Features::enabled('shop.order_tracking')) {
            return url('/track?number='.$order->number);
        }

        return url($order->user_id ? '/account/orders' : '/');
    }

    public static function render(string $template, Order $order, ?string $reason = null): string
    {
        $courier = $order->courier_id ? Courier::query()->find($order->courier_id) : null;
        $making = (int) $order->items()->where('made_to_order', true)->max('making_days');

        return trim(preg_replace('/\s+/', ' ', strtr($template, [
            '{name}' => $order->first_name,
            '{number}' => $order->number,
            '{total}' => Money::format($order->total),
            '{courier}' => $courier->name ?? 'our courier',
            '{tracking}' => $order->tracking_number ?? '—',
            '{tracking_link}' => (string) $courier?->trackingLink($order->tracking_number),
            '{track_url}' => self::trackUrl($order),
            '{making_days}' => (string) max(1, $making),
            '{reason}' => (string) $reason,
            '{site}' => Settings::string('site.name'),
        ])) ?? '');
    }
}
