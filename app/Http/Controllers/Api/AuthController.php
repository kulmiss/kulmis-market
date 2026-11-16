<?php

namespace App\Http\Controllers\Api;

use App\Events\CustomerRegistered;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\Devices;
use App\Security\LoginGuard;
use App\Security\PasswordPolicy;
use App\Security\TwoFactor;
use App\Support\Activity;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Customer accounts (token auth for the API and the future Flutter app). */
class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone'),
            'email' => $request->input('email') ? mb_strtolower(trim((string) $request->input('email'))) : null]);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['required', fn ($a, $v, $fail) => Phone::isValid($v) ?: $fail('Enter a valid Ethiopian mobile number.'), Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => PasswordPolicy::rules(false, $request->only(['phone', 'email', 'first_name', 'last_name'])),
            'device_name' => ['nullable', 'string', 'max:60'],
            'referral_code' => ['nullable', 'string', 'max:16'],
        ], ['phone.unique' => 'This phone number already has an account. Please sign in instead.',
            'email.unique' => 'This email already has an account. Please sign in instead.']);
        $user = User::query()->create(array_intersect_key($data, array_flip(['first_name', 'last_name', 'phone', 'email', 'password'])));
        CustomerRegistered::dispatch($request, $user);
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
        $user = app(LoginGuard::class)->attempt($user, $data['password'], 'api', fn (User $u) => ! $u->isStaff());
        if ($user->hasTwoFactor()) {
            // Step 2 with the code: POST auth/two-factor {challenge, code}. Valid 10 minutes.
            $challenge = encrypt(['id' => $user->id, 'exp' => now()->addMinutes(10)->getTimestamp(), 'device' => $data['device_name'] ?? 'api']);

            return response()->json(['two_factor' => true, 'challenge' => $challenge]);
        }

        return $this->issue($user, $data['device_name'] ?? 'api');
    }

    public function twoFactor(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'max:2000'], 'code' => ['required', 'string', 'max:32']]);
        try {
            $c = (array) decrypt($data['challenge']);
        } catch (\Throwable) {
            $c = [];
        }
        $user = isset($c['id'], $c['exp']) && $c['exp'] > now()->getTimestamp() ? User::query()->find($c['id']) : null;
        if (! $user || ! $user->hasTwoFactor() || ! $user->isActiveAccount() || $user->isStaff()) {
            throw ValidationException::withMessages(['challenge' => 'Your sign-in expired. Please sign in again.']);
        }
        if (! $twoFactor->check($user, $data['code'])) {
            Activity::log('security.two_factor_failed', $user, ['channel' => 'api'], null, $user->id);
            throw ValidationException::withMessages(['code' => 'That code is not right.']);
        }

        return $this->issue($user, (string) ($c['device'] ?? 'api'));
    }

    private function issue(User $user, string $device): JsonResponse
    {
        Activity::log('customer.login', $user, ['channel' => 'api'], null, $user->id);
        app(Devices::class)->newToken($user, $device);

        return response()->json(['token' => $this->token($user, $device), 'user' => self::present($user)]);
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
            'referral_code' => $user->referral_code,
            'is_member' => $user->isActiveMember(),
            'membership' => $m ? ['status' => $m->status, 'renews_at' => $m->current_period_end?->toIso8601String(),
                'access_until' => $m->accessEndsAt()?->toIso8601String()] : null,
            'pending_email' => $user->pending_email,
            'two_factor' => $user->hasTwoFactor(),
        ];
    }

    private function token(User $user, string $device): string
    {
        return $user->createToken($device, ['customer'])->plainTextToken;
    }
}
