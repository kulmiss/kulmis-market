<?php

namespace App\Signals;

use App\Jobs\SendCustomerMessage;
use App\Models\SignalPurchase;
use App\Models\SignalSubscription;
use App\Models\User;
use App\Notify\Message;
use App\Support\Activity;
use App\Support\Features;
use App\Support\Phone;
use App\Support\Settings;
use App\Telegram\TelegramClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Who may be in the private signals channel, and keeping the channel in
 * sync: personal join links in, automatic removal after access ends.
 */
class SignalAccess
{
    public function __construct(private readonly TelegramClient $telegram) {}

    /** @param array{first_name:string,last_name?:string,phone:string,email?:?string} $buyer */
    public function newGuestPurchase(array $buyer, ?User $user = null): SignalPurchase
    {
        if (Features::disabled('signals')) {
            throw new InvalidArgumentException('Signals are not available right now.');
        }
        if ($user?->isActiveMember()) {
            throw new InvalidArgumentException('Signals are included in your membership.');
        }
        $phone = Phone::normalize($buyer['phone']) ?? throw new InvalidArgumentException('Invalid phone.');
        $sub = SignalSubscription::query()->where('source', SignalSubscription::PURCHASE)->where('phone', $phone)->first()
            ?? SignalSubscription::query()->create([
                'user_id' => $user?->id, 'source' => SignalSubscription::PURCHASE, 'first_name' => $buyer['first_name'],
                'last_name' => $buyer['last_name'] ?? '', 'phone' => $phone, 'email' => $buyer['email'] ?? null,
                'status' => 'pending', 'link_token' => Str::random(32),
            ]);
        $price = Settings::int('signals.guest_price');
        $open = $sub->purchases()->where('status', 'pending')->where('amount', $price)->where('created_at', '>', now()->subDay())->latest('id')->first();

        return $open ?? $sub->purchases()->create(['amount' => $price, 'status' => 'pending']);
    }

    public function activatePurchase(SignalPurchase $purchase): SignalSubscription
    {
        return DB::transaction(function () use ($purchase) {
            $p = SignalPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $sub = SignalSubscription::query()->whereKey($p->signal_subscription_id)->lockForUpdate()->firstOrFail();
            if ($p->status === 'paid') {
                return $sub;
            }
            $continuing = $sub->isEntitled() && $sub->current_period_end?->isFuture();
            $start = $continuing ? $sub->current_period_end->copy() : now();
            $end = $start->copy()->addMonthNoOverflow();
            $sub->update(['status' => 'active', 'current_period_end' => $end,
                'grace_ends_at' => $end->copy()->addDays(Settings::int('signals.grace_days')), 'removed_at' => null]);
            $p->update(['status' => 'paid', 'paid_at' => now(), 'period_start' => $start, 'period_end' => $end]);
            Activity::log('signals.activated', $sub, ['until' => $end->toIso8601String()]);
            DB::afterCommit(fn () => $this->sendAccessLink($sub));

            return $sub;
        });
    }

    public function revokePurchase(SignalPurchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            $p = SignalPurchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== 'paid' || ! $p->period_start || ! $p->period_end) {
                return;
            }
            $p->update(['status' => 'refunded']);
            $sub = SignalSubscription::query()->whereKey($p->signal_subscription_id)->lockForUpdate()->firstOrFail();
            $newEnd = $sub->current_period_end?->copy()->subDays((int) $p->period_start->diffInDays($p->period_end));
            $ended = $newEnd === null || $newEnd->lte(now());
            $sub->update(['current_period_end' => $ended ? now() : $newEnd,
                'grace_ends_at' => $ended ? now()->subSecond() : $newEnd->copy()->addDays(Settings::int('signals.grace_days'))]);
        });
        $this->removeIfNotEntitled($purchase->subscription()->firstOrFail());
    }

    /** Members get access for free: make sure they have a subscription + link. */
    public function grantMember(User $user): SignalSubscription
    {
        $sub = SignalSubscription::query()->firstOrCreate(
            ['user_id' => $user->id, 'source' => SignalSubscription::MEMBERSHIP],
            ['first_name' => $user->first_name, 'last_name' => $user->last_name, 'phone' => $user->phone,
                'email' => $user->email, 'status' => 'active', 'link_token' => Str::random(32)],
        );
        if ($sub->status !== 'active' || $sub->removed_at) {
            $sub->update(['status' => 'active', 'removed_at' => null]);
        }

        return $sub;
    }

    /**
     * Admin: give access by hand for N days.
     *
     * @param  array<string, mixed>  $person
     */
    public function grantManual(array $person, int $days, int $actorId, string $reason): SignalSubscription
    {
        $sub = SignalSubscription::query()->create([
            'source' => SignalSubscription::MANUAL, 'first_name' => $person['first_name'], 'last_name' => $person['last_name'] ?? '',
            'phone' => Phone::normalize($person['phone'] ?? null), 'email' => $person['email'] ?? null, 'status' => 'active',
            'current_period_end' => now()->addDays($days), 'grace_ends_at' => now()->addDays($days), 'link_token' => Str::random(32),
        ]);
        Activity::log('signals.manual_grant', $sub, ['days' => $days], $reason, $actorId);
        $this->sendAccessLink($sub);

        return $sub;
    }

    /** Remove from the channel now (access ended, refund, or admin). */
    public function remove(SignalSubscription $sub, string $reason, ?int $actorId = null): void
    {
        if ($sub->telegram_user_id && $this->telegram->configured() && config('services.telegram.channel_id')) {
            try {
                $channel = config('services.telegram.channel_id');
                $this->telegram->call('banChatMember', ['chat_id' => $channel, 'user_id' => $sub->telegram_user_id, 'revoke_messages' => false]);
                // Unban straight away: removes them now but lets them join again after paying.
                $this->telegram->call('unbanChatMember', ['chat_id' => $channel, 'user_id' => $sub->telegram_user_id, 'only_if_banned' => true]);
            } catch (Throwable $e) {
                Log::warning('Telegram removal failed: '.$e->getMessage());

                return; // keep it for the next sweep
            }
        }
        $sub->update(['status' => 'ended', 'removed_at' => now()]);
        Activity::log('signals.removed', $sub, [], $reason, $actorId);
    }

    public function removeIfNotEntitled(SignalSubscription $sub): bool
    {
        if ($sub->status !== 'ended' && ! $sub->isEntitled()) {
            $this->remove($sub, 'Access ended');

            return true;
        }

        return false;
    }

    /** Hourly: remove everyone whose access (incl. grace) has ended. */
    public function sweep(): int
    {
        // While the bot is off nobody can actually be removed, so wait.
        if (Features::disabled('telegram_bot')) {
            return 0;
        }
        $removed = 0;
        $membershipOff = Features::disabled('membership');
        SignalSubscription::query()->with('user.membership')->whereIn('status', ['active', 'pending'])
            ->where(fn ($q) => $q->where('source', SignalSubscription::MEMBERSHIP)->orWhere('grace_ends_at', '<', now()))
            ->each(function (SignalSubscription $sub) use (&$removed, $membershipOff) {
                if ($sub->status === 'pending' && $sub->grace_ends_at === null) {
                    return; // never paid
                }
                if ($membershipOff && $sub->source === SignalSubscription::MEMBERSHIP) {
                    return; // membership is paused, not ended: keep members in the channel
                }
                $removed += (int) $this->removeIfNotEntitled($sub);
            });

        return $removed;
    }

    public function sendAccessLink(SignalSubscription $sub): void
    {
        $link = $sub->deepLink();
        $body = strtr(Settings::template('notify.signals_access'), [
            '{name}' => $sub->first_name, '{link}' => $link ?? '(Telegram bot not set up yet)',
            '{date}' => $sub->current_period_end?->timezone(config('app.timezone'))->format('d M Y') ?? '', '{site}' => Settings::string('site.name'),
        ]);
        SendCustomerMessage::dispatch(new Message('Your signals access', $body, $sub->phone, $sub->email, type: 'signals_access'));
    }
}
