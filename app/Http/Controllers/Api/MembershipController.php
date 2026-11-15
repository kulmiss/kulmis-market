<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Membership\MembershipService;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentService;
use App\Support\Currency;
use App\Support\Features;
use App\Support\Settings;
use App\Wallet\WalletPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MembershipController extends Controller
{
    public function __construct(private readonly MembershipService $memberships, private readonly PaymentService $payments) {}

    /** Public: price and benefits (for the landing page and app). */
    public function info(): JsonResponse
    {
        return response()->json(['data' => [
            'price' => Settings::int('membership.price'),
            'period' => 'month',
            'currency' => Currency::code(),
            'benefits' => array_values(array_filter([
                Features::enabled('membership.member_discount') ? ['key' => 'discount', 'value' => Settings::int('pricing.member_discount_bps')] : null,
                Features::enabled('signals') ? ['key' => 'signals', 'value' => Settings::int('signals.guest_price')] : null,
                Features::enabled('shop.free_shipping') ? ['key' => 'free_shipping_over', 'value' => Settings::int('shipping.member_free_over')] : null,
                Features::enabled('shop.early_access') ? ['key' => 'early_access_hours', 'value' => Settings::int('catalog.early_access_hours')] : null,
                Features::enabled('referral') ? ['key' => 'referral_earnings', 'value' => null] : null,
                Features::enabled('loyalty_reward') ? ['key' => 'loyalty_reward', 'value' => Settings::int('loyalty.reward'), 'every_months' => Settings::int('loyalty.every_months')] : null,
            ])),
            'grace_days' => Settings::int('membership.grace_days'),
        ]]);
    }

    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => AuthController::present($user)['membership'] + ['is_member' => $user->isActiveMember()]
            + ['price' => Settings::int('membership.price')]]);
    }

    /** Start paying one month (Chapa) — returns the checkout URL. */
    public function pay(Request $request): JsonResponse
    {
        $request->validate(['method' => ['required', Rule::in(['chapa', 'wallet'])], 'use_wallet' => ['nullable', 'boolean']]);
        try {
            $purchase = $this->memberships->newPurchase($request->user());
            if ($request->input('method') === 'wallet' || $request->boolean('use_wallet')) {
                app(WalletPayments::class)->apply($purchase, $request->user());
                if ($purchase->fresh()->isSettled()) {
                    return response()->json(['data' => ['paid' => true, 'amount' => 0]], 201);
                }
                if ($request->input('method') === 'wallet') {
                    throw new InvalidArgumentException('Not enough in your wallet. Pay the rest with Chapa.');
                }
            }
            abort_if(Features::disabled('payments.chapa'), 404);
            $payment = $this->payments->startChapa($purchase, url('/account?membership=paid'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['membership' => $e->getMessage()]);
        }

        return response()->json(['data' => ['checkout_url' => $payment->checkout_url, 'amount' => $payment->amount, 'paid' => false]], 201);
    }

    public function proof(Request $request): JsonResponse
    {
        abort_if(Features::disabled('payments.manual'), 404);
        $data = $request->validate([
            'method' => ['required', Rule::in([Payment::BANK, Payment::TELEBIRR])],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:120'], 'payer_account' => ['nullable', 'string', 'max:120'],
        ]);
        try {
            $purchase = $this->memberships->newPurchase($request->user());
            $this->payments->submitProof($purchase, $data['method'], $request->file('proof'), $data['reference'] ?? null, $data['payer_account'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['membership' => $e->getMessage()]);
        }

        return response()->json(['data' => ['status' => 'awaiting_review']], 201);
    }
}
