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

    /** Pay one month from the wallet (bank / Telebirr transfer: see proof()). */
    public function pay(Request $request): JsonResponse
    {
        $request->validate(['method' => ['required', Rule::in(['wallet'])]]);
        try {
            $purchase = $this->memberships->newPurchase($request->user());
            app(WalletPayments::class)->apply($purchase, $request->user(), fullOnly: true);
            if (! $purchase->fresh()->isSettled()) {
                throw new InvalidArgumentException('Not enough in your wallet. Use it for part of the price and transfer the rest (send the receipt).');
            }
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['membership' => $e->getMessage()]);
        }

        return response()->json(['data' => ['paid' => true, 'amount' => 0]], 201);
    }

    /** Bank / Telebirr transfer with a receipt; use_wallet pays part from the wallet first. */
    public function proof(Request $request): JsonResponse
    {
        abort_if(Features::disabled('payments.manual'), 404);
        $data = $request->validate([
            'method' => ['required', Rule::in([Payment::BANK, Payment::TELEBIRR])],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:120'], 'payer_account' => ['nullable', 'string', 'max:120'],
            'use_wallet' => ['nullable', 'boolean'],
        ]);
        try {
            $purchase = $this->memberships->newPurchase($request->user());
            if ($request->boolean('use_wallet')) {
                app(WalletPayments::class)->apply($purchase, $request->user());
                if ($purchase->fresh()->isSettled()) {
                    return response()->json(['data' => ['status' => 'paid']], 201);
                }
            }
            $this->payments->submitProof($purchase->fresh(), $data['method'], $request->file('proof'), $data['reference'] ?? null, $data['payer_account'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['membership' => $e->getMessage()]);
        }

        return response()->json(['data' => ['status' => 'awaiting_review']], 201);
    }
}
