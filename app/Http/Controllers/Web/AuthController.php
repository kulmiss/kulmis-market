<?php

namespace App\Http\Controllers\Web;

use App\Events\CustomerRegistered;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\Devices;
use App\Security\LoginGuard;
use App\Security\PasswordPolicy;
use App\Security\TwoFactor;
use App\Support\Activity;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Website sign-in / sign-up (session). The app uses the token API. */
class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('store.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);
        $key = 'web-login:'.mb_strtolower($data['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Too many attempts. Try again in a minute.']);
        }
        $user = User::query()->where('phone', Phone::normalize($data['login']) ?? '__')->orWhere('email', mb_strtolower($data['login']))->first();
        try {
            $user = app(LoginGuard::class)->attempt($user, $data['password'], 'web', fn (User $u) => ! $u->isStaff());
        } catch (ValidationException $e) {
            RateLimiter::hit($key);
            throw $e;
        }
        RateLimiter::clear($key);
        if ($user->hasTwoFactor()) {
            // Password was right; the session only gets the account after the code.
            $request->session()->put('login.2fa', ['id' => $user->id, 'remember' => $request->boolean('remember'), 'at' => now()->getTimestamp()]);

            return redirect('/login/two-factor');
        }

        return $this->complete($request, $user, $request->boolean('remember'));
    }

    public function showTwoFactor(Request $request): View|RedirectResponse
    {
        return $request->session()->has('login.2fa') ? view('store.auth.two-factor') : redirect('/login');
    }

    public function twoFactor(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $pending = (array) $request->session()->get('login.2fa');
        $user = isset($pending['id']) && now()->getTimestamp() - (int) $pending['at'] < 600 ? User::query()->find($pending['id']) : null;
        if (! $user || ! $user->hasTwoFactor() || ! $user->isActiveAccount()) {
            $request->session()->forget('login.2fa');

            return redirect('/login')->withErrors(['login' => 'Your sign-in expired. Please sign in again.']);
        }
        if (! $twoFactor->check($user, $data['code'])) {
            Activity::log('security.two_factor_failed', $user, ['channel' => 'web'], null, $user->id);
            throw ValidationException::withMessages(['code' => 'That code is not right. Use the newest code from your app, or a recovery code.']);
        }
        $request->session()->forget('login.2fa');

        return $this->complete($request, $user, (bool) ($pending['remember'] ?? false));
    }

    private function complete(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        Activity::log('customer.login', $user, ['channel' => 'web'], null, $user->id);
        app(Devices::class)->signedIn($user, $request, 'web');

        return redirect()->intended('/account');
    }

    public function showRegister(Request $request): View
    {
        return view('store.auth.register', ['code' => $request->cookie(config('referral.cookie'))]);
    }

    public function register(Request $request): RedirectResponse
    {
        $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone'),
            'email' => $request->input('email') ? mb_strtolower(trim((string) $request->input('email'))) : null]);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['required', fn ($a, $v, $fail) => Phone::isValid($v) ?: $fail('Enter a valid Ethiopian mobile number.'), Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => array_merge(PasswordPolicy::rules(false, $request->only(['phone', 'email', 'first_name', 'last_name'])), ['confirmed']),
            'referral_code' => ['nullable', 'string', 'max:16'],
            'terms' => ['accepted'],
        ], ['terms.accepted' => 'Please accept the terms.']);
        $user = User::query()->create(array_intersect_key($data, array_flip(['first_name', 'last_name', 'phone', 'email', 'password'])));
        CustomerRegistered::dispatch($request, $user);
        Activity::log('customer.registered', $user, [], null, $user->id);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        app(Devices::class)->signedIn($user, $request, 'web');

        return redirect('/account?welcome=1');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // The query string skips the page cache, so the home page can say "You are signed out".
        return redirect('/?signed_out=1')->withCookie(cookie()->forget((string) config('session.cookie')));
    }

    public function showForgot(): View
    {
        return view('store.auth.forgot');
    }
}
