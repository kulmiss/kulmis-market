<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendCustomerMessage;
use App\Models\Order;
use App\Models\User;
use App\Notify\Message;
use App\Security\EmailChange;
use App\Security\PasswordPolicy;
use App\Security\SecurityAlerts;
use App\Security\Sessions;
use App\Security\TwoFactor;
use App\Support\Activity;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function orders(Request $request): JsonResponse
    {
        $page = Order::query()->where('user_id', $request->user()->id)->withCount('items')->latest('id')
            ->paginate(min(50, (int) $request->input('per_page', 20)), ['number', 'status', 'total', 'wallet_used', 'created_at', 'id']);

        return response()->json($page);
    }

    public function order(Request $request, string $number): JsonResponse
    {
        $order = Order::query()->where('user_id', $request->user()->id)->where('number', strtoupper($number))->firstOrFail();

        return response()->json(['data' => TrackingController::present($order)]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->merge(['email' => $request->input('email') ? mb_strtolower(trim((string) $request->input('email'))) : null]);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        $user->update(['first_name' => $data['first_name'], 'last_name' => $data['last_name']]);
        // A new email only takes effect after the link sent to it is opened.
        $emailPending = false;
        if (! empty($data['email']) && $data['email'] !== $user->email) {
            app(EmailChange::class)->request($user, $data['email']);
            $emailPending = true;
        }

        return response()->json(['user' => AuthController::present($user->fresh()), 'email_confirmation_sent' => $emailPending]);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => array_merge(PasswordPolicy::rules(false, $request->user()->only(['phone', 'email', 'first_name', 'last_name'])), ['different:current_password']),
        ]);
        /** @var User $user */
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Wrong password.']);
        }
        $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save();
        app(Sessions::class)->signOutOthers($user, null, $user->currentAccessToken()->getKey(), false);
        app(SecurityAlerts::class)->notify($user, 'password_changed');

        return response()->json(['ok' => true]);
    }

    /** Always answers the same, so phone numbers can't be probed. */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $user = User::query()->where('phone', Phone::normalize($data['phone']) ?? '__')->where('role', User::ROLE_CUSTOMER)->first();
        if ($user && $user->isActiveAccount()) {
            $code = (string) random_int(100000, 999999);
            DB::table('password_reset_codes')->where('user_id', $user->id)->delete();
            DB::table('password_reset_codes')->insert(['user_id' => $user->id, 'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
            SendCustomerMessage::dispatch(new Message('Password reset', "Your Kulmis Market reset code is $code. It expires in 15 minutes.", $user->phone, $user->email));
        }

        return response()->json(['message' => 'If this number has an account, we sent a code.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'], 'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        $user = User::query()->where('phone', Phone::normalize($data['phone']) ?? '__')->first();
        if ($user) {
            validator($data, ['password' => PasswordPolicy::rules($user->isStaff(), $user->only(['phone', 'email', 'first_name', 'last_name']))])->validate();
        }
        $row = $user ? DB::table('password_reset_codes')->where('user_id', $user->id)->first() : null;
        if (! $row || now()->gt($row->expires_at) || $row->attempts >= 5 || ! Hash::check($data['code'], $row->code_hash)) {
            if ($row) {
                DB::table('password_reset_codes')->where('id', $row->id)->increment('attempts');
            }
            throw ValidationException::withMessages(['code' => 'The code is wrong or has expired.']);
        }
        DB::table('password_reset_codes')->where('user_id', $user->id)->delete();
        $user->forceFill(['password' => $data['password'], 'password_changed_at' => now(), 'failed_logins' => 0, 'locked_until' => null])->save();
        app(Sessions::class)->signOutOthers($user, null, null, false);
        Activity::log('customer.password_reset', $user, [], null, $user->id);
        app(SecurityAlerts::class)->notify($user, 'password_changed');

        return response()->json(['ok' => true]);
    }

    /** Sign out every website session and app token, except this one. */
    public function signOutAll(Request $request, Sessions $sessions): JsonResponse
    {
        $count = $sessions->signOutOthers($request->user(), null, $request->user()->currentAccessToken()->getKey());

        return response()->json(['ok' => true, 'ended' => $count]);
    }

    public function twoFactorSetup(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        return response()->json($twoFactor->begin($request->user()));
    }

    public function twoFactorConfirm(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);

        return response()->json(['ok' => true, 'recovery_codes' => $twoFactor->confirm($request->user(), $data['code'])]);
    }

    public function twoFactorDisable(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:32']]);
        $twoFactor->disable($request->user(), $data['password'], $data['code']);

        return response()->json(['ok' => true]);
    }
}
