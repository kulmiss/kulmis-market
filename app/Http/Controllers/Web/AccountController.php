<?php

namespace App\Http\Controllers\Web;

use App\Badges\BadgeEngine;
use App\Cart\CartManager;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Controller;
use App\Ledger\Wallet;
use App\Member\MemberDashboard;
use App\Membership\MembershipService;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutRequest;
use App\Models\Product;
use App\Models\Signal;
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
use App\Support\Settings;
use App\Wallet\CashOuts;
use App\Wallet\PayoutAccounts;
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
    public function dashboard(Request $request, MemberDashboard $dashboard): View
    {
        /** @var User $user */
        $user = $request->user();
        $member = $user->isActiveMember();
        $chart = $request->query('chart') === 'monthly' ? 'monthly' : 'daily';

        return view('member.home', [
            'user' => $user,
            'isMember' => $member,
            'dash' => $dashboard->for($user, $chart),
            'wallet' => Wallet::for($user)->summary(),
            'signalsLink' => $this->signalsLink($user, $member),
            'earlyAccess' => $member && Features::enabled('shop.early_access')
                ? Product::query()->visibleTo(true)->where('release_at', '>', now())->with(['images', 'variants'])->limit(6)->get() : collect(),
        ]);
    }

    public function team(Request $request, TeamView $team): View
    {
        abort_if(Features::disabled('referral'), 404);

        return view('member.team', ['ref' => $team->for($request->user())]);
    }

    public function earnings(Request $request, MemberDashboard $dashboard, TeamView $team): View
    {
        abort_if(Features::disabled('referral'), 404);
        $user = $request->user();

        return view('member.earnings', ['dash' => ['money' => $dashboard->money($user)], 'monthly' => $dashboard->monthly($user), 'history' => $team->history($user, 100)]);
    }

    public function wallet(Request $request): View
    {
        abort_if(Features::disabled('wallet'), 404);
        /** @var User $user */
        $user = $request->user();

        return view('member.wallet', [
            'user' => $user,
            'wallet' => Wallet::for($user)->summary(),
            'cashouts' => PayoutRequest::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'payoutAccount' => app(PayoutAccounts::class)->current($user),
        ]);
    }

    public function orders(Request $request): View
    {
        return view('member.orders', ['orders' => Order::query()->where('user_id', $request->user()->id)->latest('id')->limit(50)->get()]);
    }

    public function signals(Request $request): View
    {
        abort_if(Features::disabled('signals'), 404);
        $user = $request->user();

        return view('member.signals', [
            'isMember' => $user->isActiveMember(),
            'signalsLink' => $this->signalsLink($user, $user->isActiveMember()),
            'signals' => Signal::query()->latest('posted_at')->limit(15)->get(),
        ]);
    }

    private function signalsLink(User $user, bool $member): ?string
    {
        return $member && Features::enabled('signals') ? app(SignalAccess::class)->grantMember($user)->deepLink() : null;
    }

    public function badges(Request $request, BadgeEngine $engine): View
    {
        $engine->evaluate($request->user()); // catch up right away (thresholds may have been lowered)

        return view('store.account.badges', ['badges' => $engine->progress($request->user()), 'summary' => $engine->summary($request->user())]);
    }

    public function badgesSeen(Request $request, BadgeEngine $engine): RedirectResponse
    {
        $engine->markSeen($request->user());

        return back(fallback: url('/account/badges'));
    }

    /** Pay a month fully from the wallet. */
    public function payMembership(Request $request, MembershipService $memberships, WalletPayments $wallet): RedirectResponse
    {
        $request->validate(['method' => ['required', Rule::in(['wallet'])]]);
        try {
            $purchase = $memberships->newPurchase($request->user());
            $wallet->apply($purchase, $request->user());
            if (! $purchase->fresh()->isSettled()) {
                throw new InvalidArgumentException('Not enough in your wallet. Tick "use my wallet" and transfer the rest.');
            }
        } catch (InvalidArgumentException|\RuntimeException $e) {
            return back()->withErrors(['membership' => $e->getMessage()]);
        }

        return redirect('/account')->with('status', 'Membership paid from your wallet. Welcome!');
    }

    /** Bank / Telebirr transfer with a receipt, optionally after using the wallet for part of it. */
    public function membershipProof(Request $request, MembershipService $memberships, PaymentService $payments, WalletPayments $wallet): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in([Payment::BANK, Payment::TELEBIRR])],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'use_wallet' => ['nullable', 'boolean'],
        ]);
        try {
            $purchase = $memberships->newPurchase($request->user());
            if ($request->boolean('use_wallet')) {
                $wallet->apply($purchase, $request->user());
                if ($purchase->fresh()->isSettled()) {
                    return redirect('/account')->with('status', 'Membership paid from your wallet. Welcome!');
                }
            }
            $payments->submitProof($purchase->fresh(), $data['method'], $request->file('proof'), $data['reference'] ?? null, $request->user()->phone);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['membership' => $e->getMessage()]);
        }

        return redirect('/account')->with('status', 'Thank you! We will confirm your payment shortly.');
    }

    public function cashOut(Request $request, CashOuts $cashOuts): RedirectResponse
    {
        $data = $request->validate(['amount_birr' => ['required', 'numeric', 'min:1', 'max:1000000'], 'request_id' => ['nullable', 'string', 'max:64']]);
        // request_id is a random value in the form: a double click submits it twice, we pay once.
        $cashOuts->request($request->user(), (int) round((float) $data['amount_birr'] * 100), $data['request_id'] ?? null);

        return redirect('/account#wallet')->with('status', 'Cash-out requested. We pay within '.Settings::int('wallet.payout_days').' days.');
    }

    public function payoutAccount(Request $request, PayoutAccounts $accounts): RedirectResponse
    {
        $data = $request->validate(WalletController::accountRules()
            + ['current_password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:32']]);
        $account = $accounts->save($request->user(), $data, $data['current_password'], $data['code'] ?? null);

        return redirect('/account#wallet')->with('status', 'Cash-out account saved. For your safety you can cash out to it from '
            .$account->usable_after->timezone(config('app.timezone'))->format('d M H:i').'.');
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
