<?php

namespace App\Http\Controllers\Api;

use App\Cart\CartManager;
use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Orders\Checkout;
use App\Pricing\PriceCalculator;
use App\Pricing\PricingException;
use App\Shop\Bundles;
use App\Shop\FlashSales;
use App\Shop\Recommendations;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function __construct(private readonly CartManager $carts, private readonly Checkout $checkout) {}

    public function show(Request $request): JsonResponse
    {
        $request->validate(['shipping_zone_id' => ['nullable', 'integer'], 'city' => ['nullable', 'string', 'max:80']]);

        return $this->respond($this->carts->find($request), $request);
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);
        $cart = $this->carts->findOrCreate($request);
        $this->carts->add($cart, $data['product_id'], $data['variant_id'] ?? null, $data['quantity'] ?? 1);

        return $this->respond($cart, $request, 201);
    }

    /** "Add the set to cart": every piece of a live bundle in one go. */
    public function addBundle(Request $request, int $bundle): JsonResponse
    {
        $set = app(Bundles::class)->live()->firstWhere('id', $bundle);
        if (! $set instanceof Bundle) {
            throw new PricingException('This set is not available any more.');
        }
        $cart = $this->carts->findOrCreate($request);
        DB::transaction(function () use ($set, $cart) {
            foreach ($set->items as $i) {
                $this->carts->add($cart, $i->product_id, $i->product_variant_id, $i->quantity);
            }
        });

        return $this->respond($cart, $request, 201);
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:99']]);
        $cart = $this->carts->find($request);
        abort_if($cart === null, 404);
        $this->carts->setQuantity($cart, $item, $data['quantity']);

        return $this->respond($cart, $request);
    }

    public function coupon(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:40']]);
        $cart = $this->carts->find($request);
        abort_if($cart === null, 404);
        $previous = $cart->coupon_code;
        $cart->update(['coupon_code' => $data['code'] ? strtoupper(trim($data['code'])) : null]);
        try {
            $this->checkout->preview($cart, $this->user($request));
        } catch (PricingException $e) {
            $cart->update(['coupon_code' => $previous]);
            throw $e;
        }

        return $this->respond($cart, $request);
    }

    private function respond(?Cart $cart, Request $request, int $status = 200): JsonResponse
    {
        $user = $this->user($request);
        $items = $cart ? $cart->items()->with(['product.images', 'variant'])->get() : collect();
        $quote = null;
        $error = null;
        if ($cart && $items->isNotEmpty()) {
            try {
                $quote = $this->checkout->preview($cart, $user, $request->integer('shipping_zone_id') ?: null, $request->input('city'))->toArray();
            } catch (PricingException $e) {
                $error = $e->getMessage();
            }
        }

        return response()->json([
            'token' => $cart?->token,
            'items' => $items->map(fn ($i) => [
                'id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->product_variant_id,
                'name' => $i->product->name.($i->variant ? ' — '.$i->variant->name : ''),
                'slug' => $i->product->slug, 'quantity' => $i->quantity,
                'image' => $i->product->images->first()?->url(400, in_array('webp', $i->product->images->first()->formats, true) ? 'webp' : 'jpg'),
            ]),
            'coupon_code' => $cart?->coupon_code,
            'quote' => $quote,
            'problem' => $error,
            'is_member' => (bool) $user?->isActiveMember(),
            'suggestions' => $this->suggestions($items->pluck('product_id')->unique()->values()->all(), (bool) $user?->isActiveMember()),
        ], $status);
    }

    /**
     * "You may also like" for the cart and checkout, with a one-tap add when the
     * product has no options to choose.
     *
     * @param  list<int>  $productIds
     * @return list<array<string, mixed>>
     */
    private function suggestions(array $productIds, bool $member): array
    {
        if ($productIds === [] || Features::disabled('shop.upsells')) {
            return [];
        }
        $prices = app(PriceCalculator::class);
        $flash = app(FlashSales::class);

        return app(Recommendations::class)->forCart($productIds, $member)->map(function (Product $p) use ($member, $prices, $flash) {
            $sale = $flash->for($p);
            $list = $sale !== null && $sale->price < $p->price ? $sale->price : $p->price;
            $stock = $p->availableStock();
            $img = $p->images->first();

            return [
                'product_id' => $p->id, 'name' => $p->name, 'slug' => $p->slug,
                'price' => $member ? $prices->memberPrice($list) : $list, 'regular_price' => $p->price,
                'image' => $img?->url(400, in_array('webp', $img->formats, true) ? 'webp' : 'jpg'),
                'one_tap' => $p->isPurchasableBy($member) && $p->variants->where('is_active', true)->isEmpty() && ($stock === null || $stock > 0),
            ];
        })->values()->all();
    }

    private function user(Request $request): ?User
    {
        $u = auth('sanctum')->user() ?? $request->user();

        return $u instanceof User ? $u : null;
    }
}
