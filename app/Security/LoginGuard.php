<?php

namespace App\Security;

use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Checks a password and keeps count of wrong ones per account. After
 * `security.lockout_attempts` wrong passwords in a row the account pauses for
 * `security.lockout_minutes` (website, app and admin alike) and the owner is
 * told. The per-IP throttles on the routes still apply on top.
 */
class LoginGuard
{
    public function __construct(private readonly SecurityAlerts $alerts) {}

    /**
     * Returns the user when the password is right; throws a validation error
     * (on $field) otherwise. $allowed decides who may use this door.
     *
     * @param  \Closure(User): bool  $allowed
     */
    public function attempt(?User $user, string $password, string $channel, \Closure $allowed, string $field = 'login', string $wrong = 'Wrong phone/email or password.'): User
    {
        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            Activity::log('security.login_while_locked', $user, ['channel' => $channel], null, $user->id);
            $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));
            throw ValidationException::withMessages([$field => "Too many wrong passwords. For your safety, sign-in is paused for $minutes more minute".($minutes === 1 ? '' : 's').'.']);
        }

        // Always run a hash check so response time doesn't reveal whether the account exists.
        $ok = Hash::check($password, $user->password ?? '$2y$12$1TKy4OhOHQo0c/kNlEjCf.l/JRfEZbNNua28UeG3RqEFS18tMEG4W');
        if (! $user || ! $ok || ! $allowed($user) || ! $user->isActiveAccount()) {
            if ($user && ! $ok) {
                $this->failed($user, $channel);
            }
            throw ValidationException::withMessages([$field => $wrong]);
        }

        if ($user->failed_logins > 0 || $user->locked_until) {
            $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();
        }

        return $user;
    }

    private function failed(User $user, string $channel): void
    {
        $max = max(1, (int) config('security.lockout_attempts'));
        $count = $user->failed_logins + 1;
        Activity::log('security.login_failed', $user, ['channel' => $channel, 'count' => $count], null, $user->id);
        if ($count >= $max) {
            $user->forceFill(['failed_logins' => 0, 'locked_until' => now()->addMinutes((int) config('security.lockout_minutes'))])->save();
            $this->alerts->notify($user, 'locked', ['channel' => $channel]);
            if ($user->isStaff()) {
                $this->alerts->critical('Admin account paused after '.$max.' wrong passwords', ['user' => $user->id, 'channel' => $channel]);
            }

            return;
        }
        $user->forceFill(['failed_logins' => $count])->save();
    }
}
