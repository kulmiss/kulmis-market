<?php

namespace App\Fraud;

use App\Models\Commission;
use App\Models\FraudFlag;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Security\SecurityAlerts;
use App\Support\Activity;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * Anti-fraud: self-referral (same phone/email/device/payment account),
 * daily sign-up limits, shared payout accounts. An open flag puts the
 * member's earnings and cash-outs on hold until Super Admin reviews it.
 */
class FraudGuard
{
    public function flag(User|int $user, string $type, array $details = []): FraudFlag
    {
        $id = $user instanceof User ? $user->id : $user;
        $existing = FraudFlag::query()->where('user_id', $id)->where('type', $type)->where('status', 'open')->first();
        if ($existing) {
            $existing->update(['details' => array_merge((array) $existing->details, ['repeat' => (int) (($existing->details['repeat'] ?? 0) + 1)])]);

            return $existing;
        }
        $flag = FraudFlag::query()->create(['user_id' => $id, 'type' => $type, 'details' => $details, 'status' => 'open']);
        Activity::log('fraud.flagged', $flag, ['type' => $type]);
        app(SecurityAlerts::class)->critical('Fraud check: '.str_replace('_', ' ', $type)." (member #$id) — earnings and cash-outs are on hold until you review it.", ['flag' => $flag->id]);

        return $flag;
    }

    /**
     * Review-only flags: shown in the fraud queue but they don't hold money.
     * Network matches are weak evidence in Ethiopia (many mobile users share
     * one carrier IP), so they never block earnings on their own.
     */
    public const SOFT = ['same_network_signups'];

    public function isOnHold(User|int $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return FraudFlag::query()->where('user_id', $id)->where('status', 'open')->whereNotIn('type', self::SOFT)->exists();
    }

    /** Same device as the referrer (hashed cart token, no tracking cookie). */
    public function sameDevice(User $referrer, ?string $deviceHash): bool
    {
        if ($deviceHash === null) {
            return false;
        }

        return $referrer->signup_device_hash === $deviceHash
            || Order::query()->where('user_id', $referrer->id)->where('device_hash', $deviceHash)->exists();
    }

    /** The account that paid belongs to the recipient (phone or payout account). */
    public function paidWithOwnAccount(User $recipient, ?string $payerAccount): bool
    {
        if (! $payerAccount) {
            return false;
        }
        $phone = Phone::normalize($payerAccount);
        if ($phone && $phone === $recipient->phone) {
            return true;
        }

        return PayoutRequest::query()->where('user_id', $recipient->id)
            ->where('account_fingerprint', self::fingerprint($payerAccount))->exists();
    }

    public function checkDailyLimit(User $referrer): void
    {
        $limit = Settings::int('referral.daily_signup_limit');
        $today = User::query()->where('referrer_id', $referrer->id)->where('referred_at', '>=', now()->startOfDay())->count();
        if ($limit > 0 && $today > $limit) {
            $this->flag($referrer, 'daily_limit', ['today' => $today, 'limit' => $limit]);
        }
    }

    /**
     * Fake-account patterns around one referrer. Their new sign-ups are still
     * linked (they may be real people), but the referrer's earnings and
     * cash-outs wait for review:
     * - two or more of their sign-ups came from the same browser (cart token hash);
     * - many sign-ups for them from the same public network within 24 hours
     *   (review only — see SOFT).
     *   The network is only kept as a keyed hash in the cache for 24 hours —
     *   never written to the database.
     */
    public function checkFakeSignups(User $referrer, User $newUser, ?string $deviceHash, string $ip): void
    {
        if ($deviceHash !== null) {
            $sameDevice = User::query()->where('referrer_id', $referrer->id)->where('signup_device_hash', $deviceHash)
                ->where('created_at', '>=', now()->subDays(30))->count();
            if ($sameDevice >= 2) {
                $this->flag($referrer, 'shared_device_signups', ['users' => $sameDevice, 'latest' => $newUser->id]);
            }
        }
        // Local/private networks (your own PC, office Wi-Fi, a proxy) say nothing about people.
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            $net = str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 4)) : implode('.', array_slice(explode('.', $ip), 0, 3));
            $key = 'fraud:signup-net:'.$referrer->id.':'.hash_hmac('sha256', $net, (string) config('app.key'));
            Cache::add($key, 0, now()->addDay());
            $count = (int) Cache::increment($key);
            if ($count >= max(3, Settings::int('referral.same_network_signups'))) {
                $this->flag($referrer, 'same_network_signups', ['count_24h' => $count, 'latest' => $newUser->id]);
            }
        }
    }

    /** Super Admin review. Clearing releases holds; confirming keeps them. */
    public function review(FraudFlag $flag, string $decision, int $actorId, ?string $note, bool $suspend = false): void
    {
        $flag->update(['status' => $decision, 'reviewed_by' => $actorId, 'reviewed_at' => now(), 'note' => $note]);
        Activity::log('fraud.'.$decision, $flag, ['suspend' => $suspend], $note, $actorId);
        if ($decision === 'cleared' && ! $this->isOnHold($flag->user_id)) {
            PayoutRequest::query()->where('user_id', $flag->user_id)->where('status', 'on_hold')
                ->update(['status' => 'requested', 'hold_reason' => null]);
            Commission::query()->where('recipient_id', $flag->user_id)->where('on_hold', true)->update(['on_hold' => false]);
        }
        if ($decision === 'confirmed' && $suspend) {
            $user = User::query()->findOrFail($flag->user_id);
            $user->forceFill(['status' => 'suspended'])->save();
            $user->tokens()->delete();
        }
    }

    public static function fingerprint(string $account): string
    {
        $normalized = Phone::normalize($account) ?? preg_replace('/\D+/', '', $account);

        return hash_hmac('sha256', (string) $normalized, (string) config('app.key'));
    }
}
