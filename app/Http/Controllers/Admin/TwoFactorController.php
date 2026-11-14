<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity;
use App\Support\Totp;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    /** Start (or restart) setup: returns the secret and a QR code to scan. */
    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['two_factor' => 'Two-factor authentication is already on.']);
        }
        $secret = Totp::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();
        $uri = Totp::uri($secret, $user->email ?? (string) $user->phone, config('app.name'));

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_svg' => (new QRCode)->render($uri),
        ]);
    }

    /** Confirm with a code from the app; returns one-time recovery codes. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        /** @var User $user */
        $user = $request->user();
        if (! $user->two_factor_secret || $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Start the setup first.']);
        }
        $step = Totp::verify($user->two_factor_secret, $data['code']);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'Invalid code.']);
        }
        $codes = collect(range(1, 8))->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => $codes,
        ])->save();
        Activity::log('admin.2fa_enabled', $user);

        return response()->json(['ok' => true, 'recovery_codes' => $codes]);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        /** @var User $user */
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Wrong password.']);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
        Activity::log('admin.2fa_disabled', $user);

        return response()->json(['ok' => true]);
    }
}
