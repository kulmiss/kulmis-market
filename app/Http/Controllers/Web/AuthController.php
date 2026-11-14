<?php

namespace App\Http\Controllers\Web;

use App\Events\CustomerRegistered;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
        if (! $user || $user->isStaff() || ! $user->isActiveAccount() || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages(['login' => 'Wrong phone/email or password.']);
        }
        RateLimiter::clear($key);
        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

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
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'referral_code' => ['nullable', 'string', 'max:16'],
            'terms' => ['accepted'],
        ], ['terms.accepted' => 'Please accept the terms.']);
        $user = User::query()->create(collect($data)->only(['first_name', 'last_name', 'phone', 'email', 'password'])->all());
        CustomerRegistered::dispatch($request, $user);
        Activity::log('customer.registered', $user, [], null, $user->id);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect('/account?welcome=1');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->withCookie(cookie()->forget((string) config('session.cookie')));
    }

    public function showForgot(): View
    {
        return view('store.auth.forgot');
    }
}
