<?php

namespace App\Fraud;

use App\Models\Commission;
use App\Models\FraudFlag;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Support\Activity;
use App\Support\Phone;
use App\Support\Settings;

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

        return $flag;
    }

    public function isOnHold(User|int $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return FraudFlag::query()->where('user_id', $id)->where('status', 'open')->exists();
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
