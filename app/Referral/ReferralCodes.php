<?php

namespace App\Referral;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Short, easy codes like ABDI25: unique, no offensive or reserved words. */
class ReferralCodes
{
    public function generateFor(User $user): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z]/', '', Str::ascii($user->first_name)) ?? '');
        $base = substr($base !== '' ? $base : 'KM', 0, 6);
        if (strlen($base) < 2) {
            $base .= 'KM';
        }
        for ($i = 0; $i < 50; $i++) {
            $code = $base.($i < 30 ? random_int(10, 99) : random_int(100, 9999));
            if ($this->isAllowed($code) && ! $this->taken($code)) {
                return $code;
            }
        }

        return 'KM'.strtoupper(Str::random(6));
    }

    public function assign(User $user): void
    {
        if ($user->referral_code === null && ! $user->isStaff()) {
            $user->forceFill(['referral_code' => $this->generateFor($user)])->save();
        }
    }

    /** The member may change their code once. */
    public function change(User $user, string $code): void
    {
        $code = $this->normalize($code);
        if ($user->referral_code_changed_at !== null) {
            throw ValidationException::withMessages(['code' => 'You can change your code only once.']);
        }
        $min = config('referral.min_length');
        $max = config('referral.max_length');
        if (! preg_match('/^[A-Z0-9]{'.$min.','.$max.'}$/', $code)) {
            throw ValidationException::withMessages(['code' => "Use {$min}–{$max} letters or numbers."]);
        }
        if (! $this->isAllowed($code)) {
            throw ValidationException::withMessages(['code' => 'Please choose a different code.']);
        }
        if ($code !== $user->referral_code && $this->taken($code)) {
            throw ValidationException::withMessages(['code' => 'That code is already taken.']);
        }
        $user->forceFill(['referral_code' => $code, 'referral_code_changed_at' => now()])->save();
    }

    public function isAllowed(string $code): bool
    {
        $code = $this->normalize($code);
        // Catch l33t spellings too (0→O, 1→I, 3→E, 4→A, 5→S, 7→T).
        $plain = strtr($code, ['0' => 'O', '1' => 'I', '3' => 'E', '4' => 'A', '5' => 'S', '7' => 'T']);
        foreach (config('referral.blocked_words') as $word) {
            if (str_contains($code, $word) || str_contains($plain, $word)) {
                return false;
            }
        }

        return true;
    }

    public function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function findOwner(?string $code): ?User
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return User::query()->where('referral_code', $this->normalize($code))->first();
    }

    private function taken(string $code): bool
    {
        return User::query()->withoutGlobalScopes()->where('referral_code', $code)->exists(); // hidden demo codes are taken too
    }
}
