<?php

namespace App\Http\Controllers\Web;

use App\Cart\CartManager;
use App\Http\Controllers\Controller;
use App\Ledger\Wallet;
use App\Membership\MembershipService;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutRequest;
use App\Models\Product;
use App\Models\User;
use App\Orders\Checkout;
use App\Orders\CheckoutExtras;
use App\Orders\OrderService;
use App\Payments\PaymentService;
use App\Pricing\PricingException;
use App\Referral\ReferralCodes;
use App\Referral\TeamView;
use App\Signals\SignalAccess;
use App\Support\Features;
use App\Support\Phone;
use App\Wallet\CashOuts;
use App\Wallet\WalletPayments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** The member area: dashboard, referral, wallet, cash-out, orders. */
class AccountController extends Controller
{
    public function dashboard(Request $request, TeamView $team): View
    {
        /** @var User $user */
        $user = $request->user();
        $member = $user->isActiveMember();
        $signals = $member && Features::enabled('signals') ? app(SignalAccess::class)->grantMember($user) : null;

        return view('store.account.dashboard', [
            'user' => $user,
            'membership' => $user->membership()->first(),
            'isMember' => $member,
            'referral' => Features::enabled('referral') ? $team->for($user) : null,
            'history' => Features::enabled('referral') ? $team->history($user, 20) : collect(),
            'wallet' => Wallet::for($user)->summary(),
            'cashouts' => PayoutRequest::query()->where('user_id', $user->id)->latest('id')->limit(10)->get(),
            'orders' => Order::query()->where('user_id', $user->id)->latest('id')->limit(10)->get(),
            'signalsLink' => $signals?->deepLink(),
            'earlyAccess' => $member && Features::enabled('shop.early_access')
                ? Product::query()->visibleTo(true)->where('release_at', '>', now())->with(['images', 'variants'])->limit(6)->get() : collect(),
        ]);
    }

    public function payMembership(Request $request, MembershipService $memberships, PaymentService $payments, WalletPayments $wallet): RedirectResponse
    {
        $data = $request->validate(['method' => ['required', Rule::in(['chapa', 'wallet', 'wallet_chapa'])]]);
        try {
            $purchase = $memberships->newPurchase($request->user());
            if (in_array($data['method'], ['wallet', 'wallet_chapa'], true)) {
                $wallet->apply($purchase, $request->user());
                if ($purchase->fresh()->isSettled()) {
                    return redirect('/account')->with('status', 'Membership paid from your wallet. Welcome!');
                }
                if ($data['method'] === 'wallet') {
                    throw new InvalidArgumentException('Not enough in your wallet — pay the rest with Chapa.');
                }
            }
            $payment = $payments->startChapa($purchase, url('/account?paid=1'));
        } catch (InvalidArgumentException|\RuntimeException $e) {
            return back()->withErrors(['membership' => $e->getMessage()]);
        }

        return redirect()->away((string) $payment->checkout_url);
    }

    public function membershipProof(Request $request, MembershipService $memberships, PaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in([Payment::BANK, Payment::TELEBIRR])],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $payments->submitProof($memberships->newPurchase($request->user()), $data['method'], $request->file('proof'), $data['reference'] ?? null, $request->user()->phone);

        return redirect('/account')->with('status', 'Thank you! We will confirm your payment shortly.');
    }

    public function cashOut(Request $request, CashOuts $cashOuts): RedirectResponse
    {
        $data = $request->validate([
            'amount_birr' => ['required', 'numeric', 'min:1'],
            'method' => ['required', Rule::in(['telebirr', 'bank'])],
            'account_name' => ['required', 'string', 'max:160'],
            'account_number' => ['required', 'string', 'max:40', 'regex:/^[0-9+\s-]{6,40}$/'],
            'bank_name' => ['nullable', 'required_if:method,bank', 'string', 'max:120'],
        ]);
        $cashOuts->request($request->user(), ['amount' => (int) round((float) $data['amount_birr'] * 100)] + $data);

        return redirect('/account#wallet')->with('status', 'Cash-out requested. We pay within 3 days.');
    }

    public function referralCode(Request $request, ReferralCodes $codes): RedirectResponse
    {
        $codes->change($request->user(), (string) $request->validate(['code' => ['required', 'string', 'max:16']])['code']);

        return redirect('/account#referral')->with('status', 'Your new code is saved.');
    }

    /** Checkout for signed-in customers (member prices, wallet). */
    public function checkout(Request $request, CartManager $carts, Checkout $checkout, WalletPayments $wallet): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['required', fn ($a, $v, $fail) => Phone::isValid($v) ?: $fail('Enter a valid Ethiopian mobile number.')],
            'email' => ['nullable', 'email', 'max:255'], 'city' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:500'], 'shipping_zone_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'], 'use_wallet' => ['nullable', 'boolean'],
            'checkout_key' => ['required', 'string', 'min:16', 'max:64'],
        ]);
        $cart = $carts->find($request);
        if (! $cart) {
            return redirect('/cart');
        }
        try {
            $extra = app(CheckoutExtras::class)->collect($request, $cart, $user, $data);
            $order = $checkout->place($cart, $data, $user, $data['checkout_key'], $extra);
        } catch (PricingException $e) {
            throw ValidationException::withMessages(['cart' => $e->getMessage()]);
        }
        if ($order->wasRecentlyCreated && $request->boolean('use_wallet')) {
            $wallet->apply($order, $user);
            $order->refresh();
        }
        if ($order->wasRecentlyCreated && $order->amountDue() === 0 && $order->status === Order::PENDING_PAYMENT) {
            app(OrderService::class)->markPaid($order, 'Nothing left to pay');
            $order->refresh();
        }

        return $order->status === Order::PENDING_PAYMENT
            ? redirect('/pay/'.$order->number)
            : redirect('/track?number='.$order->number)->with('status', 'Order placed and paid. Thank you!');
    }

    public function newCheckoutKey(): string
    {
        return (string) Str::uuid();
    }
}
