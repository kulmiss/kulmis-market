<?php

namespace App\Security;

use App\Jobs\SendCustomerMessage;
use App\Models\User;
use App\Notify\Message;
use App\Support\Settings;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Changing the email takes two steps: we email a link to the NEW address and
 * only switch after it's opened (within 24 h). The OLD address is told right
 * away, so a stolen session can't quietly take over the account.
 */
class EmailChange
{
    public function __construct(private readonly SecurityAlerts $alerts) {}

    public function request(User $user, string $newEmail): void
    {
        $newEmail = mb_strtolower(trim($newEmail));
        if ($newEmail === mb_strtolower((string) $user->email)) {
            return;
        }
        if (User::query()->where('email', $newEmail)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email is already used by another account.']);
        }
        $token = Str::random(48);
        $user->forceFill(['pending_email' => $newEmail, 'pending_email_token' => hash('sha256', $token),
            'pending_email_expires_at' => now()->addHours((int) config('security.email_change_hours'))])->save();

        $link = url('/account/email/confirm/'.$token);
        SendCustomerMessage::dispatch(new Message('Confirm your new email', "Hi {$user->first_name}, open this link to use this email for your "
            .Settings::string('site.name')." account: $link\nThe link works for ".config('security.email_change_hours').' hours. If you did not ask for this, ignore this message.',
            null, $newEmail), null, true);
        if ($user->email || $user->phone) {
            $this->alerts->notify($user, 'email_change_requested', ['new_email' => $newEmail]);
        }
    }

    /** Returns the user whose email changed, or null for a bad/expired link. */
    public function confirm(string $token): ?User
    {
        $user = User::query()->where('pending_email_token', hash('sha256', $token))->first();
        if (! $user || ! $user->pending_email || ! $user->pending_email_expires_at || $user->pending_email_expires_at->isPast()) {
            return null;
        }
        if (User::query()->where('email', $user->pending_email)->whereKeyNot($user->id)->exists()) {
            $user->forceFill(['pending_email' => null, 'pending_email_token' => null, 'pending_email_expires_at' => null])->save();

            return null;
        }
        $old = $user->email;
        $new = $user->pending_email;
        $user->forceFill(['email' => $new, 'email_verified_at' => now(), 'pending_email' => null,
            'pending_email_token' => null, 'pending_email_expires_at' => null])->save();
        // Tell both the old and the new address.
        if ($old) {
            $this->alerts->notify($user, 'email_changed', ['new_email' => $new], $old);
        }
        $this->alerts->notify($user, 'email_changed', ['new_email' => $new]);

        return $user;
    }
}
