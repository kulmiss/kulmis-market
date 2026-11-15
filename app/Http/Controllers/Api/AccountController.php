<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendCustomerMessage;
use App\Models\Order;
use App\Models\User;
use App\Notify\Message;
use App\Support\Activity;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
        $user->update($data);

        return response()->json(['user' => AuthController::present($user)]);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'different:current_password'],
        ]);
        /** @var User $user */
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Wrong password.']);
        }
        $user->update(['password' => $data['password']]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->getKey())->delete();
        Activity::log('customer.password_changed', $user);

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
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
        ]);
        $user = User::query()->where('phone', Phone::normalize($data['phone']) ?? '__')->first();
        $row = $user ? DB::table('password_reset_codes')->where('user_id', $user->id)->first() : null;
        if (! $row || now()->gt($row->expires_at) || $row->attempts >= 5 || ! Hash::check($data['code'], $row->code_hash)) {
            if ($row) {
                DB::table('password_reset_codes')->where('id', $row->id)->increment('attempts');
            }
            throw ValidationException::withMessages(['code' => 'The code is wrong or has expired.']);
        }
        DB::table('password_reset_codes')->where('user_id', $user->id)->delete();
        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();
        Activity::log('customer.password_reset', $user, [], null, $user->id);

        return response()->json(['ok' => true]);
    }
}
