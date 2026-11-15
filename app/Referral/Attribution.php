<?php

namespace App\Referral;

use App\Cart\CartManager;
use App\Fraud\FraudGuard;
use App\Models\Order;
use App\Models\SignalSubscription;
use App\Models\User;
use App\Notify\Inbox;
use App\Support\Activity;
use App\Support\Features;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

/**
 * Who referred whom.
 * - The link (?ref=CODE or /r/CODE) sets the essential `km_ref` cookie (only the
 *   code, 30 days). The latest link clicked wins.
 * - At sign-up the referrer is set and LOCKED. Only Super Admin can change it.
 * - Guests (no account) are attributed per order / per signals subscription.
 */
class Attribution
{
    public function __construct(private readonly ReferralCodes $codes) {}

    public function rememberFromLink(string $code): void
    {
        if (Features::disabled('referral') || ! $this->codes->findOwner($code)) {
            return;
        }
        $days = Settings::int('referral.cookie_days');
        Cookie::queue(Cookie::make(config('referral.cookie'), $this->codes->normalize($code), $days * 24 * 60, '/', null, null, true, false, 'lax'));
    }

    /** Explicit code field wins over the cookie (it's the backup). */
    public function codeFrom(Request $request): ?string
    {
        $code = $request->input('referral_code') ?: $request->cookie(config('referral.cookie'));

        return is_string($code) && $code !== '' ? $this->codes->normalize($code) : null;
    }

    /** @return array{0:?User, 1:?string} [referrer, reason it was refused] */
    public function validReferrer(?string $code, ?User $buyer, ?string $phone = null, ?string $email = null): array
    {
        if (Features::disabled('referral')) {
            return [null, 'disabled'];
        }
        $owner = $this->codes->findOwner($code);
        if (! $owner) {
            return [null, $code ? 'unknown_code' : null];
        }
        if ($this->isSamePerson($owner, $buyer, $phone, $email)) {
            return [null, 'self_referral'];
        }

        return [$owner, null];
    }

    public function attachAtSignup(Request $request, User $user): void
    {
        $this->codes->assign($user);
        $device = app(CartManager::class)->deviceHash(app(CartManager::class)->find($request));
        if ($device) {
            $user->forceFill(['signup_device_hash' => $device])->save();
        }
        $code = $this->codeFrom($request);
        [$referrer, $refused] = $this->validReferrer($code, $user, $user->phone, $user->email);
        $guard = app(FraudGuard::class);
        if ($referrer) {
            // Same browser as the referrer (shared family phone, internet café, or
            // the same person twice): still link them so the team is right, but
            // the referrer's earnings wait for Super Admin's review.
            if ($guard->sameDevice($referrer, $device)) {
                $guard->flag($referrer, 'same_browser_signup', ['new_user' => $user->id]);
                Activity::log('referral.same_browser_linked', $user, ['referrer' => $referrer->id], null, $user->id);
            }
            $user->forceFill(['referrer_id' => $referrer->id, 'referred_at' => now()])->save();
            Inbox::add($referrer, 'team', $user->first_name.' joined your team', 'They signed up with your link or code. When they pay for a membership you earn from it.',
                '/account/team', "team:{$user->id}");
            $guard->checkDailyLimit($referrer);
            $guard->checkFakeSignups($referrer, $user, $device, (string) $request->ip());
        } elseif ($refused === 'self_referral') {
            Activity::log('referral.self_referral_blocked', $user, ['code' => $code], null, $user->id);
        }
        if ($request->input('referral_code') && $refused === 'unknown_code') {
            throw ValidationException::withMessages(['referral_code' => 'This referral code does not exist.']);
        }
    }

    /**
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed> order columns
     */
    public function forOrder(Request $request, ?User $buyer, array $customer): array
    {
        // Registered buyers: the locked sign-up referrer is used (see CommissionEngine).
        if ($buyer) {
            return [];
        }
        $code = $this->codeFrom($request);
        [$referrer] = $this->validReferrer($code, null, Phone::normalize($customer['phone'] ?? null), $customer['email'] ?? null);

        return $referrer ? ['referrer_user_id' => $referrer->id, 'referral_code' => $referrer->referral_code] : [];
    }

    public function forSignals(Request $request, SignalSubscription $sub): void
    {
        if ($sub->referrer_user_id || $sub->user_id) {
            return; // locked at first purchase / account holders use their sign-up referrer
        }
        [$referrer] = $this->validReferrer($this->codeFrom($request), null, $sub->phone, $sub->email);
        if ($referrer) {
            $sub->update(['referrer_user_id' => $referrer->id]);
        }
    }

    /** Super Admin only: change a locked referrer (with a reason; no loops). */
    public function changeReferrer(User $user, ?User $newReferrer, string $reason, int $actorId): void
    {
        if ($newReferrer) {
            if ($newReferrer->id === $user->id || $this->isInDownline($user, $newReferrer)) {
                throw ValidationException::withMessages(['referrer_id' => 'That would create a loop in the referral tree.']);
            }
        }
        $old = $user->referrer_id;
        $user->forceFill(['referrer_id' => $newReferrer?->id, 'referred_at' => $newReferrer ? ($user->referred_at ?? now()) : null])->save();
        Activity::log('referral.referrer_changed', $user, ['from' => $old, 'to' => $newReferrer?->id], $reason, $actorId);
    }

    public function isSamePerson(User $owner, ?User $buyer, ?string $phone, ?string $email): bool
    {
        if ($buyer && $buyer->id === $owner->id) {
            return true;
        }
        $phone = Phone::normalize($phone);
        if ($phone && $owner->phone === $phone) {
            return true;
        }

        return $email && $owner->email && mb_strtolower($email) === mb_strtolower($owner->email);
    }

    private function isInDownline(User $root, User $candidate): bool
    {
        $ids = [$root->id];
        for ($depth = 0; $depth < 50 && $ids; $depth++) {
            $ids = User::query()->whereIn('referrer_id', $ids)->pluck('id')->all();
            if (in_array($candidate->id, $ids, true)) {
                return true;
            }
        }

        return false;
    }
}
