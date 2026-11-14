<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity;
use App\Support\Phone;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Customer accounts (token auth for the API and the future Flutter app). */
class AuthController extends Controller
{
    /** Hooks for later steps (referral code at sign-up). @var list<Closure> */
    public static array $afterRegister = [];

    public function register(Request $request): JsonResponse
    {
        $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone'),
            'email' => $request->input('email') ? mb_strtolower(trim((string) $request->input('email'))) : null]);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['required', fn ($a, $v, $fail) => Phone::isValid($v) ?: $fail('Enter a valid Ethiopian mobile number.'), Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);
        $user = User::query()->create(collect($data)->only(['first_name', 'last_name', 'phone', 'email', 'password'])->all());
        foreach (self::$afterRegister as $hook) {
            $hook($request, $user);
        }
        Activity::log('customer.registered', $user, [], null, $user->id);

        return response()->json(['token' => $this->token($user, $data['device_name'] ?? 'api'), 'user' => self::present($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:60']]);
        $login = $data['login'];
        $user = User::query()->where('phone', Phone::normalize($login) ?? '__none__')
            ->orWhere('email', mb_strtolower($login))->first();
        // Staff use the admin login (with 2FA), never API tokens.
        if (! $user || $user->isStaff() || ! $user->isActiveAccount() || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => 'Wrong phone/email or password.']);
        }

        return response()->json(['token' => $this->token($user, $data['device_name'] ?? 'api'), 'user' => self::present($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => self::present($request->user())]);
    }

    /** @return array<string, mixed> */
    public static function present(User $user): array
    {
        $m = $user->membership()->first();

        return [
            'id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name,
            'phone' => $user->phone, 'email' => $user->email,
            'is_member' => $user->isActiveMember(),
            'membership' => $m ? ['status' => $m->status, 'renews_at' => $m->current_period_end?->toIso8601String(),
                'access_until' => $m->accessEndsAt()?->toIso8601String()] : null,
        ];
    }

    private function token(User $user, string $device): string
    {
        return $user->createToken($device, ['customer'])->plainTextToken;
    }
}
