<?php

namespace App\Shop;

use App\Events\OrderPaid;
use App\Jobs\SendCustomerMessage;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Notify\Inbox;
use App\Notify\Message;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * After a digital product is paid: one private link per file, working for
 * N days and M downloads (Settings). Links stop when the order is refunded
 * or cancelled. Files live on the private disk and are never public.
 */
class DigitalDelivery
{
    public function paid(OrderPaid $event): void
    {
        if (Features::disabled('shop.digital')) {
            return;
        }
        $order = $event->order->loadMissing('items.product.files');
        $links = [];
        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product instanceof Product || ! $product->isDigital()) {
                continue;
            }
            foreach ($product->files as $file) {
                /** @var ProductFile $file */
                if (DownloadGrant::query()->where('order_id', $order->id)->where('product_file_id', $file->id)->exists()) {
                    continue; // never twice
                }
                $token = Str::random(48);
                DownloadGrant::query()->create(['order_id' => $order->id, 'product_file_id' => $file->id, 'token_hash' => hash('sha256', $token),
                    'max_downloads' => max(1, Settings::int('digital.max_downloads')), 'expires_at' => now()->addDays(max(1, Settings::int('digital.link_days')))]);
                $links[] = $file->title.': '.url('/downloads/'.$token);
            }
        }
        if ($links === []) {
            return;
        }
        $body = "Thank you for your order {$order->number}! Your downloads (each link works ".Settings::int('digital.link_days').' days, '
            .Settings::int('digital.max_downloads')." times):\n".implode("\n", $links);
        SendCustomerMessage::dispatch(new Message('Your downloads: '.$order->number, $body, $order->phone, $order->email, type: 'order_paid'));
        if ($order->user_id && ($u = User::query()->find($order->user_id))) {
            Inbox::add($u, 'order', "Your downloads for {$order->number} are ready", 'We sent you the links. They are also in My orders.', '/account/orders', "downloads:{$order->id}");
        }
    }

    /** A fresh link for the buyer's own order page (the old one keeps its own count). */
    public static function newLink(DownloadGrant $grant): string
    {
        $token = Str::random(48);
        $grant->update(['token_hash' => hash('sha256', $token)]);

        return url('/downloads/'.$token);
    }

    public static function usable(DownloadGrant $g): bool
    {
        return $g->expires_at->isFuture() && $g->downloads < $g->max_downloads
            && in_array($g->order->status, [Order::PAID, Order::MAKING, Order::SHIPPED, Order::DELIVERED], true);
    }
}
