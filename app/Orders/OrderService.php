<?php

namespace App\Orders;

use App\Events\OrderPaid;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusChange;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Activity;
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
            if ($item->product_variant_id) {
                ProductVariant::query()->whereKey($item->product_variant_id)->increment('stock', $item->quantity);
            } elseif ($item->product_id) {
                Product::query()->whereKey($item->product_id)->increment('stock', $item->quantity);
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
