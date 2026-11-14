<?php

namespace App\Http\Controllers\Api;

use App\Cart\CartManager;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Orders\Checkout;
use App\Orders\CheckoutExtras;
use App\Orders\OrderService;
use App\Support\Features;
use App\Support\Phone;
use App\Wallet\WalletPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(private readonly CartManager $carts, private readonly Checkout $checkout) {}

    public function store(Request $request): JsonResponse
    {
        $user = auth('sanctum')->user() ?? $request->user();
        $user = $user instanceof User ? $user : null;
        if (! $user && Features::disabled('shop.guest_checkout')) {
            return response()->json(['message' => 'Please sign in to check out.', 'login_required' => true], 403);
        }

        $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'checkout_key' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['required', 'string', fn ($a, $v, $fail) => Phone::isValid($v) ?: $fail('Enter a valid Ethiopian mobile number.')],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:500'],
            'shipping_zone_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'referral_code' => ['nullable', 'string', 'max:16'],
            'use_wallet' => ['nullable', 'boolean'],
        ]);
        $cart = $this->carts->find($request);
        abort_unless($cart, 422, 'Your cart is empty.');

        $extra = app(CheckoutExtras::class)->collect($request, $cart, $user, $data);
        $order = $this->checkout->place($cart, $data, $user, $data['checkout_key'], $extra);
        if ($order->wasRecentlyCreated && $user && $request->boolean('use_wallet')) {
            app(WalletPayments::class)->apply($order, $user);
            $order->refresh();
        }
        if ($order->wasRecentlyCreated && $order->amountDue() === 0 && $order->status === Order::PENDING_PAYMENT) {
            app(OrderService::class)->markPaid($order, 'Nothing left to pay');
            $order->refresh();
        }

        return response()->json(['data' => $this->present($order)], $order->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array<string, mixed> */
    public static function present(Order $order): array
    {
        return [
            'number' => $order->number,
            'status' => $order->status,
            'total' => $order->total,
            'amount_due' => $order->status === Order::PENDING_PAYMENT ? $order->amountDue() : 0,
            'wallet_used' => $order->wallet_used,
            'currency' => config('shop.currency'),
            'member_discount' => $order->member_discount,
            'shipping' => $order->shippingPayable(),
            'track_url' => url('/track?number='.$order->number),
            'payment_methods' => $order->status === Order::PENDING_PAYMENT ? PaymentController::methods() : [],
        ];
    }
}
