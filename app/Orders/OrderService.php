<?php

namespace App\Orders;

use App\Events\OrderPaid;
use App\Events\OrderStatusChanged;
use App\Jobs\NotifyBackInStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusChange;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Activity;
use App\Wallet\WalletPayments;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderService
{
    public function transition(Order $order, string $to, ?string $note = null, ?int $actorId = null): OrderStatusChange
    {
        return DB::transaction(function () use ($order, $to, $note, $actorId) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $locked->canMoveTo($to)) {
                throw new InvalidArgumentException("Order {$locked->number} cannot move from {$locked->status} to $to.");
            }
            $from = $locked->status;
            $changes = ['status' => $to];
            match ($to) {
                Order::PAID => $changes['paid_at'] = $locked->paid_at ?? now(),
                Order::SHIPPED => $changes['shipped_at'] = now(),
                Order::DELIVERED => $changes['delivered_at'] = now(),
                Order::CANCELLED => $changes['cancelled_at'] = now(),
                default => null,
            };
            $locked->update($changes);
            if ($to === Order::CANCELLED) {
                $locked->update(['cancel_reason' => $note]);
                $this->restock($locked);
                $this->releaseCoupon($locked);
                if ($locked->wallet_used > 0 && $from === Order::PENDING_PAYMENT) {
                    app(WalletPayments::class)->releaseFor($locked, 'Order cancelled');
                }
            }
            $change = $locked->history()->create(['from' => $from, 'to' => $to, 'note' => $note, 'actor_id' => $actorId]);
            $order->setRawAttributes($locked->getAttributes(), true);
            Activity::log('order.'.$to, $locked, ['from' => $from], $note, $actorId);

            DB::afterCommit(function () use ($locked, $change, $to) {
                OrderStatusChanged::dispatch($locked, $change);
                if ($to === Order::PAID) {
                    OrderPaid::dispatch($locked);
                }
            });

            return $change;
        });
    }

    public function markPaid(Order $order, ?string $note = null, ?int $actorId = null): void
    {
        if ($order->status === Order::PENDING_PAYMENT) {
            $this->transition($order, Order::PAID, $note, $actorId);
        }
    }

    public function cancel(Order $order, string $reason, ?int $actorId = null): void
    {
        $this->transition($order, Order::CANCELLED, $reason, $actorId);
    }

    public function restock(Order $order): void
    {
        foreach ($order->items as $item) {
            /** @var OrderItem $item */
            if ($item->made_to_order) {
                continue;
            }
            // Was it sold out? Then the people waiting for it get a message.
            if ($item->product_variant_id) {
                $was = (int) ProductVariant::query()->whereKey($item->product_variant_id)->value('stock');
                ProductVariant::query()->whereKey($item->product_variant_id)->increment('stock', $item->quantity);
                if ($was < 1 && $item->product_id) {
                    NotifyBackInStock::dispatch($item->product_id, $item->product_variant_id)->afterCommit();
                }
            } elseif ($item->product_id) {
                $was = (int) Product::query()->whereKey($item->product_id)->value('stock');
                Product::query()->whereKey($item->product_id)->increment('stock', $item->quantity);
                if ($was < 1) {
                    NotifyBackInStock::dispatch($item->product_id, null)->afterCommit();
                }
            }
        }
    }

    private function releaseCoupon(Order $order): void
    {
        if ($order->coupon_id) {
            $deleted = DB::table('coupon_redemptions')->where('order_id', $order->id)->delete();
            if ($deleted) {
                DB::table('coupons')->where('id', $order->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            }
        }
    }
}
