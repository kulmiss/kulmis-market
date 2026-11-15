<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\EmailChange;
use App\Security\PasswordPolicy;
use App\Security\SecurityAlerts;
use App\Security\Sessions;
use App\Security\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Member area → Security: password, email, two-step sign-in, devices. */
class SecurityController extends Controller
{
    public function show(Request $request, Sessions $sessions): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('store.account.security', [
            'user' => $user,
            'sessions' => $sessions->list($user, $request->session()->getId()),
            'devices' => DB::table('user_devices')->where('user_id', $user->id)->orderByDesc('last_seen_at')->limit(10)->get(['label', 'last_seen_at', 'created_at']),
            'setup' => $request->session()->get('2fa.setup'),
            'recoveryCodes' => $request->session()->get('2fa.codes'),
            'minLength' => PasswordPolicy::minLength(),
        ]);
    }

    public function password(Request $request, Sessions $sessions, SecurityAlerts $alerts): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => array_merge(PasswordPolicy::rules($user->isStaff(), $user->only(['phone', 'email', 'first_name', 'last_name'])), ['confirmed', 'different:current_password']),
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is not right.']);
        }
        $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save();
        $sessions->signOutOthers($user, $request->session()->getId(), null, false);
        Auth::guard('web')->login($user); // keeps this browser signed in with the new password hash
        $request->session()->regenerate();
        $alerts->notify($user, 'password_changed');

        return redirect('/account/security')->with('status', 'Password changed. Other devices were signed out.');
    }

    public function email(Request $request, EmailChange $change): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'current_password' => ['required', 'string']]);
        /** @var User $user */
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is not right.']);
        }
        $change->request($user, $data['email']);

        return redirect('/account/security')->with('status', 'We sent a link to '.$data['email'].'. Your email changes when you open it.');
    }

    /** The link from the email. Works signed in or not. */
    public function confirmEmail(string $token, EmailChange $change): RedirectResponse
    {
        $user = $change->confirm($token);

        return $user
            ? redirect(Auth::check() ? '/account/security' : '/login')->with('status', 'Your email is now '.$user->email.'.')
            : redirect(Auth::check() ? '/account/security' : '/login')->withErrors(['email' => 'This link is not valid any more. Ask for a new one.']);
    }

    public function twoFactorBegin(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $setup = $twoFactor->begin($request->user());
        $request->session()->put('2fa.setup', ['qr_svg' => $setup['qr_svg'], 'secret' => $setup['secret']]);

        return redirect('/account/security#two-factor');
    }

    public function twoFactorConfirm(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $codes = $twoFactor->confirm($request->user(), $data['code']);
        $request->session()->forget('2fa.setup');

        return redirect('/account/security#two-factor')->with('2fa.codes', $codes)->with('status', 'Two-step sign-in is on.');
    }

    public function twoFactorDisable(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'code' => ['required', 'string', 'max:32']]);
        try {
            $twoFactor->disable($request->user(), $data['current_password'], $data['code']);
        } catch (ValidationException $e) {
            // The form field is called current_password on this page.
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($v, $k) => [$k === 'password' ? 'current_password' : $k => $v])->all());
        }

        return redirect('/account/security#two-factor')->with('status', 'Two-step sign-in is off.');
    }

    public function signOutEverywhere(Request $request, Sessions $sessions): RedirectResponse
    {
        $sessions->signOutOthers($request->user(), $request->session()->getId());

        return redirect('/account/security')->with('status', 'All other devices and apps were signed out.');
    }
}
