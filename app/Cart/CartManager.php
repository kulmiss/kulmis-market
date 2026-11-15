<?php

namespace App\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Pricing\PricingException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Carts are identified by an essential first-party cookie (`km_cart`) that
 * holds only a random token, or by the `X-Cart-Token` header for the app.
 */
class CartManager
{
    public const COOKIE = 'km_cart';

    public function find(Request $request): ?Cart
    {
        $token = $request->header('X-Cart-Token') ?: $request->cookie(self::COOKIE);
        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        return Cart::query()->where('token', $token)->first();
    }

    public function findOrCreate(Request $request): Cart
    {
        $cart = $this->find($request);
        if ($cart === null) {
            $cart = Cart::query()->create(['token' => Str::random(40), 'user_id' => $request->user()?->id]);
            Cookie::queue(Cookie::make(self::COOKIE, $cart->token, 60 * 24 * 60, '/', null, null, true, false, 'lax'));
        }

        return $cart;
    }

    public function add(Cart $cart, int $productId, ?int $variantId, int $quantity): CartItem
    {
        $product = Product::query()->where('status', 'active')->find($productId);
        if (! $product) {
            throw new PricingException('That product is not available.');
        }
        if ($variantId !== null && ! ProductVariant::query()->where('product_id', $productId)->where('is_active', true)->whereKey($variantId)->exists()) {
            throw new PricingException('That option is not available.');
        }
        $item = $cart->items()->firstOrNew(['product_id' => $productId, 'product_variant_id' => $variantId]);
        $item->quantity = min(99, (int) $item->quantity + $quantity);
        $item->save();

        return $item;
    }

    public function setQuantity(Cart $cart, int $itemId, int $quantity): void
    {
        $item = $cart->items()->whereKey($itemId)->firstOrFail();
        $quantity <= 0 ? $item->delete() : $item->update(['quantity' => min(99, $quantity)]);
    }

    /** @return list<array{0: Product, 1: ?ProductVariant, 2: int}> */
    public function pricingItems(Cart $cart): array
    {
        return $cart->items()->with(['product.variants', 'variant'])->get()
            ->map(fn (CartItem $i) => [$i->product, $i->variant, $i->quantity])->values()->all();
    }

    /** Hashed cart token: a device marker for fraud checks without a tracking cookie. */
    public function deviceHash(?Cart $cart): ?string
    {
        return $cart ? hash_hmac('sha256', $cart->token, (string) config('app.key')) : null;
    }
}
