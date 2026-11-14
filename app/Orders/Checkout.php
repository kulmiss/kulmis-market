<?php

namespace App\Orders;

use App\Cart\CartManager;
use App\Events\OrderPlaced;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Pricing\PriceCalculator;
use App\Pricing\PricingException;
use App\Pricing\Quote;
use App\Shipping\ShippingQuoter;
use Illuminate\Support\Facades\DB;

/**
 * Turns a cart into an order. Safe against double submits (checkout_key)
 * and overselling (stock rows are locked and decremented in one transaction).
 */
class Checkout
{
    public function __construct(
        private readonly PriceCalculator $prices,
        private readonly ShippingQuoter $shipping,
        private readonly CartManager $carts,
    ) {}

    /** Price the cart for display (no locks, no changes). */
    public function preview(Cart $cart, ?User $user, ?int $zoneId = null, ?string $city = null, ?string $phone = null): Quote
    {
        $items = $this->carts->pricingItems($cart);
        $member = (bool) $user?->isActiveMember();
        $weight = array_sum(array_map(fn ($i) => ($i[1]?->weight_grams ?? $i[0]->weight_grams) * $i[2], $items));
        $fee = $items && ($zoneId || $city) ? $this->shipping->quote($zoneId, (string) $city, $weight) : 0;

        return $this->prices->quote($items, $member, $cart->coupon_code, $fee, $this->couponUses($user, $phone));
    }

    /**
     * @param  array{first_name:string,last_name?:string,phone:string,email?:?string,city:string,address:string,shipping_zone_id?:?int,notes?:?string}  $customer
     * @param  array<string, mixed>  $extra  additional order columns from later steps (referral, wallet...)
     */
    public function place(Cart $cart, array $customer, ?User $user, string $checkoutKey, array $extra = []): Order
    {
        if ($existing = Order::query()->where('checkout_key', $checkoutKey)->first()) {
            return $existing; // double-click / retry → same order
        }

        $order = DB::transaction(function () use ($cart, $customer, $user, $checkoutKey, $extra) {
            $cartItems = $cart->items()->get();
            if ($cartItems->isEmpty()) {
                throw new PricingException('Your cart is empty.');
            }
            // Lock stock rows in a stable order.
            $products = Product::query()->whereKey($cartItems->pluck('product_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $variantIds = $cartItems->pluck('product_variant_id')->filter()->unique()->sort()->values();
            $variants = $variantIds->isEmpty() ? collect() : ProductVariant::query()->whereKey($variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($products as $p) {
                $p->setRelation('variants', ProductVariant::query()->where('product_id', $p->id)->orderBy('position')->get());
            }

            $items = $cartItems->map(function ($i) use ($products, $variants) {
                $product = $products->get($i->product_id);
                if (! $product) {
                    throw new PricingException('A product in your cart is no longer available.');
                }

                return [$product, $i->product_variant_id ? $variants->get($i->product_variant_id) : null, (int) $i->quantity];
            })->values()->all();

            $member = (bool) $user?->isActiveMember();
            $weight = array_sum(array_map(fn ($i) => ($i[1]?->weight_grams ?? $i[0]->weight_grams) * $i[2], $items));
            $fee = $this->shipping->quote($customer['shipping_zone_id'] ?? null, $customer['city'], $weight);
            $quote = $this->prices->quote($items, $member, $cart->coupon_code, $fee, $this->couponUses($user, $customer['phone']));

            $order = Order::query()->create(array_merge([
                'number' => $this->newNumber(),
                'user_id' => $user?->id,
                'first_name' => $customer['first_name'],
                'last_name' => $customer['last_name'] ?? '',
                'phone' => $customer['phone'],
                'email' => $customer['email'] ?? null,
                'city' => $customer['city'],
                'address' => $customer['address'],
                'shipping_zone_id' => $customer['shipping_zone_id'] ?? null,
                'notes' => $customer['notes'] ?? null,
                'status' => Order::PENDING_PAYMENT,
                'is_member_order' => $member,
                'list_subtotal' => $quote->listSubtotal,
                'member_discount' => $quote->memberDiscount,
                'coupon_id' => $quote->coupon?->id,
                'coupon_code' => $quote->coupon?->code,
                'coupon_discount' => $quote->couponDiscount,
                'merchandise_total' => $quote->merchandiseTotal(),
                'shipping' => $quote->shipping,
                'shipping_discount' => $quote->shippingDiscount,
                'total' => $quote->total(),
                'checkout_key' => $checkoutKey,
                'device_hash' => $this->carts->deviceHash($cart),
            ], $extra));

            foreach ($quote->lines as $line) {
                $order->items()->create([
                    'product_id' => $line->product->id,
                    'product_variant_id' => $line->variant?->id,
                    'name' => $line->product->name.($line->variant ? ' — '.$line->variant->name : ''),
                    'sku' => $line->variant?->sku,
                    'quantity' => $line->quantity,
                    'list_unit_price' => $line->listUnitPrice,
                    'unit_price' => $line->unitPrice,
                    'unit_cost' => $line->unitCost(),
                    'line_total' => $line->total(),
                    'made_to_order' => $line->product->isMadeToOrder(),
                    'making_days' => $line->product->making_days,
                ]);
                if (! $line->product->isMadeToOrder()) {
                    $line->variant
                        ? ProductVariant::query()->whereKey($line->variant->id)->decrement('stock', $line->quantity)
                        : Product::query()->whereKey($line->product->id)->decrement('stock', $line->quantity);
                }
            }

            if ($quote->coupon) {
                Coupon::query()->whereKey($quote->coupon->id)->increment('used_count');
                DB::table('coupon_redemptions')->insert(['coupon_id' => $quote->coupon->id, 'order_id' => $order->id,
                    'user_id' => $user?->id, 'phone' => $customer['phone'], 'created_at' => now()]);
            }
            $order->history()->create(['from' => null, 'to' => Order::PENDING_PAYMENT, 'note' => 'Order placed']);

            $cart->items()->delete();
            $cart->update(['coupon_code' => null]);

            return $order;
        });

        OrderPlaced::dispatch($order);

        return $order;
    }

    private function couponUses(?User $user, ?string $phone): \Closure
    {
        return fn (Coupon $c) => DB::table('coupon_redemptions')->where('coupon_id', $c->id)
            ->where(fn ($q) => $q->when($user, fn ($w) => $w->orWhere('user_id', $user?->id))
                ->when($phone, fn ($w) => $w->orWhere('phone', $phone)))
            ->when(! $user && ! $phone, fn ($q) => $q->whereRaw('1 = 0'))
            ->count();
    }

    private function newNumber(): string
    {
        do {
            $number = 'KM'.now()->format('ymd').'-'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }
}
