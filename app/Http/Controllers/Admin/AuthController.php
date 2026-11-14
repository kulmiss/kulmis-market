<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
            ->where('email', $data['login'])
            ->orWhere('phone', $data['login'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->isStaff() || ! $user->isActiveAccount()) {
            throw ValidationException::withMessages(['login' => 'These credentials do not match an admin account.']);
        }

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

        if (! empty($data['recovery_code'])) {
            $codes = $user->two_factor_recovery_codes ?? [];
            $match = array_search(strtoupper(trim($data['recovery_code'])), $codes, true);
            if ($match === false) {
                throw ValidationException::withMessages(['recovery_code' => 'Invalid recovery code.']);
            }
            unset($codes[$match]);
            $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
            Activity::log('admin.recovery_code_used', $user, ['remaining' => count($codes)], null, $user->id);
        } else {
            $step = Totp::verify((string) $user->two_factor_secret, (string) ($data['code'] ?? ''), $user->two_factor_last_step);
            if ($step === null) {
                throw ValidationException::withMessages(['code' => 'Invalid code.']);
            }
            $user->forceFill(['two_factor_last_step' => $step])->save();
        }

        $request->session()->forget('admin.2fa.pending');

        return $this->completeLogin($request, $user);
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
            'abilities' => $user->isSuperAdmin() ? ['*'] : config("permissions.roles.{$user->role}.abilities", []),
            'two_factor' => $user->hasTwoFactor(),
            'two_factor_required' => (bool) config('permissions.require_2fa'),
        ]);
    }

    private function completeLogin(Request $request, User $user): JsonResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        Activity::log('admin.login', $user, [], null, $user->id);

        return response()->json(['ok' => true, 'two_factor_setup_required' => config('permissions.require_2fa') && ! $user->hasTwoFactor()]);
    }
}
