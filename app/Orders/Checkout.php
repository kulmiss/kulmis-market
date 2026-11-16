<?php

namespace App\Orders;

use App\Cart\CartManager;
use App\Events\OrderPlaced;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\FlashSale;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Pricing\PriceCalculator;
use App\Pricing\PricingException;
use App\Pricing\Quote;
use App\Shipping\ShippingQuoter;
use App\Shop\Deposits;
use App\Shop\FlashSales;
use App\Shop\LowStock;
use App\Storefront\PageCache;
use App\Support\Features;
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

    /** @param list<array{0: Product, 1: ?ProductVariant, 2: int}> $items  Nothing to ship → no delivery fee. */
    public static function onlyDigital(array $items): bool
    {
        return $items !== [] && count(array_filter($items, fn ($i) => ! $i[0]->isDigital())) === 0;
    }

    /** Price the cart for display (no locks, no changes). */
    public function preview(Cart $cart, ?User $user, ?int $zoneId = null, ?string $city = null, ?string $phone = null): Quote
    {
        $items = $this->carts->pricingItems($cart);
        $member = (bool) $user?->isActiveMember();
        $weight = array_sum(array_map(fn ($i) => ($i[1]->weight_grams ?? $i[0]->weight_grams) * $i[2], $items));
        $ship = $items && ($zoneId || $city) && ! self::onlyDigital($items);
        $fee = $ship ? $this->shipping->quote($zoneId, (string) $city, $weight) : 0;
        $courier = $ship ? $this->shipping->courierCost($zoneId, (string) $city, $weight) : 0;

        return $this->prices->quote($items, $member, $cart->coupon_code, $fee, $this->couponUses($user, $phone), true, (array) ($cart->extras ?? []), $courier);
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

        $flashTouched = false; // true when a sale sold out with this order
        $order = DB::transaction(function () use ($cart, $customer, $user, $checkoutKey, $extra, &$flashTouched) {
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
            $weight = array_sum(array_map(fn ($i) => ($i[1]->weight_grams ?? $i[0]->weight_grams) * $i[2], $items));
            $fee = self::onlyDigital($items) ? 0 : $this->shipping->quote($customer['shipping_zone_id'] ?? null, $customer['city'], $weight);
            $courier = self::onlyDigital($items) ? 0 : $this->shipping->courierCost($customer['shipping_zone_id'] ?? null, $customer['city'], $weight);
            $quote = $this->prices->quote($items, $member, $cart->coupon_code, $fee, $this->couponUses($user, $customer['phone']), true, (array) ($cart->extras ?? []), $courier);

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
                'bundle_discount' => $quote->bundleDiscount,
                'coupon_id' => $quote->coupon?->id,
                'coupon_code' => $quote->coupon?->code,
                'coupon_discount' => $quote->couponDiscount,
                'merchandise_total' => $quote->merchandiseTotal(),
                'shipping' => $quote->shipping,
                'shipping_discount' => $quote->shippingDiscount,
                'courier_cost' => $quote->courierCost,
                'total' => $quote->total(),
                'extras' => $quote->extras ?: null,
                'extras_total' => $quote->extrasTotal(),
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
                    'flash_sale_id' => $line->flashSaleId,
                    'deposit_bps' => Features::enabled('shop.preorders') ? $line->product->preorder_deposit_bps : null,
                ]);
                if ($line->flashSaleId !== null) {
                    // Atomic: two checkouts can't both take the last pieces at the sale price.
                    $taken = FlashSale::query()->whereKey($line->flashSaleId)
                        ->where(fn ($q) => $q->whereNull('stock_limit')->orWhereRaw('sold + ? <= stock_limit', [$line->quantity]))
                        ->increment('sold', $line->quantity);
                    if ($taken === 0) {
                        throw new PricingException("The sale price for “{$line->product->name}” just ran out. Please check your cart again.");
                    }
                    $flashTouched = $flashTouched || FlashSale::query()->whereKey($line->flashSaleId)
                        ->whereNotNull('stock_limit')->whereColumn('sold', '>=', 'stock_limit')->exists();
                }
                if (! $line->product->isMadeToOrder()) {
                    $line->variant
                        ? ProductVariant::query()->whereKey($line->variant->id)->decrement('stock', $line->quantity)
                        : Product::query()->whereKey($line->product->id)->decrement('stock', $line->quantity);
                    LowStock::afterSale($line->product, $line->variant, $line->quantity);
                }
            }

            if (Features::enabled('shop.preorders') && ($deposit = Deposits::forPreorder($order->load('items'))) > 0) {
                $order->update(['deposit_amount' => $deposit]);
            }

            if ($quote->coupon) {
                // Atomic: two checkouts can't both take the last use.
                $taken = Coupon::query()->whereKey($quote->coupon->id)
                    ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses'))
                    ->increment('used_count');
                if ($taken === 0) {
                    throw new PricingException('This coupon has been used up.');
                }
                DB::table('coupon_redemptions')->insert(['coupon_id' => $quote->coupon->id, 'order_id' => $order->id,
                    'user_id' => $user?->id, 'phone' => $customer['phone'], 'created_at' => now()]);
            }
            $order->history()->create(['from' => null, 'to' => Order::PENDING_PAYMENT, 'note' => 'Order placed']);

            $cart->items()->delete();
            $cart->update(['coupon_code' => null, 'reminded_at' => null, 'extras' => null, 'gift_card_id' => null]);

            return $order;
        });

        if ($flashTouched) {
            app(FlashSales::class)->forget();
            PageCache::clear(); // a sale just sold out: cached guest pages must stop showing it
        }
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

    public function newNumber(): string
    {
        do {
            $number = 'KM'.now()->format('ymd').'-'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        } while (Order::query()->withoutGlobalScopes()->where('number', $number)->exists());

        return $number;
    }
}
