<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\Devices;
use App\Security\LoginGuard;
use App\Security\Sessions;
use App\Security\TwoFactor;
use App\Support\Activity;
use App\Support\Currency;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Step 1: email/phone + password. With 2FA on, a code is required next. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::query()
            ->where('email', mb_strtolower($data['login']))
            ->orWhere('phone', Phone::normalize($data['login']) ?? $data['login'])
            ->first();
        $user = app(LoginGuard::class)->attempt($user, $data['password'], 'admin', fn (User $u) => $u->isStaff(),
            wrong: 'These credentials do not match an admin account.');

        if ($user->hasTwoFactor()) {
            $request->session()->put('admin.2fa.pending', $user->id);

            return response()->json(['two_factor' => true]);
        }

        return $this->completeLogin($request, $user);
    }

    /** Step 2: TOTP code or a one-time recovery code. */
    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:32'], 'recovery_code' => ['nullable', 'string', 'max:64']]);
        $user = User::query()->find($request->session()->get('admin.2fa.pending'));
        if (! $user || ! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Your login session expired. Sign in again.']);
        }

        $recovery = ! empty($data['recovery_code']);
        if (! app(TwoFactor::class)->check($user, (string) ($recovery ? $data['recovery_code'] : ($data['code'] ?? '')))) {
            Activity::log('security.two_factor_failed', $user, ['channel' => 'admin'], null, $user->id);
            throw ValidationException::withMessages([$recovery ? 'recovery_code' : 'code' => $recovery ? 'Invalid recovery code.' : 'Invalid code.']);
        }

        $request->session()->forget('admin.2fa.pending');

        return $this->completeLogin($request, $user);
    }

    /** Sign out every other browser/device of this admin. */
    public function signOutOthers(Request $request, Sessions $sessions): JsonResponse
    {
        $count = $sessions->signOutOthers($request->user(), $request->session()->getId());

        return response()->json(['ok' => true, 'ended' => $count]);
    }

    public function logout(Request $request): JsonResponse
    {
        Activity::log('admin.logout');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => config("permissions.roles.{$user->role}.label"),
            'abilities' => $user->isSuperAdmin() ? ['*'] : array_values(array_merge(
                config("permissions.roles.{$user->role}.abilities", []),
                $user->can('view-payments') ? ['view-payments'] : [],   // combined ability (see AppServiceProvider)
                $user->can('answer-help') ? ['answer-help'] : [],
            )),
            'two_factor' => $user->hasTwoFactor(),
            'two_factor_required' => (bool) config('permissions.require_2fa'),
            'currency' => Currency::forClients(),
        ]);
    }

    private function completeLogin(Request $request, User $user): JsonResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('admin.signed_in_at', now()->getTimestamp());
        $request->session()->put('admin.last_seen_at', now()->getTimestamp());
        Activity::log('admin.login', $user, [], null, $user->id);
        app(Devices::class)->signedIn($user, $request, 'admin');

        return response()->json(['ok' => true, 'two_factor_setup_required' => config('permissions.require_2fa') && ! $user->hasTwoFactor()]);
    }
}
