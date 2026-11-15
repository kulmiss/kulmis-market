<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin 2FA (required for staff; see EnsureTwoFactor). */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    /** Start (or restart) setup: returns the secret and a QR code to scan. */
    public function setup(Request $request): JsonResponse
    {
        return response()->json($this->twoFactor->begin($request->user()));
    }

    /** Confirm with a code from the app; returns one-time recovery codes. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);

        return response()->json(['ok' => true, 'recovery_codes' => $this->twoFactor->confirm($request->user(), $data['code'])]);
    }

    /** Reset (e.g. new phone): needs the password and a current or recovery code. Staff must set it up again right after. */
    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:32']]);
        /** @var User $user */
        $user = $request->user();
        $this->twoFactor->disable($user, $data['password'], $data['code']);

        return response()->json(['ok' => true]);
    }
}
