<?php

namespace App\Signals;

use App\Models\SignalSubscription;
use App\Models\User;
use App\Support\Activity;
use App\Telegram\TelegramClient;

/**
 * Handles Telegram updates:
 *  - "/start <token>" in a private chat binds the Telegram account to the
 *    subscription (once) and replies with a personal join link.
 *  - chat_join_request on the channel: approve only entitled, bound accounts.
 *    A forwarded link is useless to anybody else.
 */
class TelegramBot
{
    public function __construct(private readonly TelegramClient $telegram) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        if (isset($update['chat_join_request'])) {
            $this->joinRequest((array) $update['chat_join_request']);

            return;
        }
        $msg = (array) ($update['message'] ?? []);
        $text = trim((string) ($msg['text'] ?? ''));
        $from = (int) data_get($msg, 'from.id', 0);
        $chat = data_get($msg, 'chat.id');
        if ($from === 0 || $chat === null || data_get($msg, 'chat.type') !== 'private') {
            return;
        }
        if (preg_match('/^\/start\s+([A-Za-z0-9]{20,64})$/', $text, $m)) {
            $this->start($m[1], $from, $chat);
        } elseif ($text === '/status') {
            $sub = $this->entitledFor($from);
            $this->telegram->sendMessage($chat, $sub
                ? 'Your access is active'.($sub->current_period_end ? ' until '.$sub->current_period_end->timezone(config('app.timezone'))->format('d M Y') : '').'.'
                : 'You have no active signals access.');
        } else {
            $this->telegram->sendMessage($chat, 'Open the personal link we sent you after payment to get access.');
        }
    }

    private function start(string $token, int $telegramUserId, int|string $chat): void
    {
        $sub = SignalSubscription::query()->where('link_token', $token)->first();
        if (! $sub || ! $sub->isEntitled()) {
            $this->telegram->sendMessage($chat, 'This link is not active. Please renew your access.');

            return;
        }
        if ($sub->telegram_user_id && $sub->telegram_user_id !== $telegramUserId) {
            $this->telegram->sendMessage($chat, 'This link is already used by another Telegram account. Contact support if this is you.');
            Activity::log('signals.link_reuse_blocked', $sub, ['telegram_user_id' => $telegramUserId]);

            return;
        }
        $sub->update(['telegram_user_id' => $telegramUserId]);
        if ($sub->user_id) {
            User::query()->whereKey($sub->user_id)->whereNull('telegram_user_id')->update(['telegram_user_id' => $telegramUserId]);
        }
        $invite = $this->telegram->call('createChatInviteLink', [
            'chat_id' => config('services.telegram.channel_id'),
            'name' => 'sub-'.$sub->id,
            'creates_join_request' => true,
            'expire_date' => now()->addDay()->timestamp,
        ]);
        $sub->update(['invite_link' => $invite['invite_link'] ?? null]);
        $this->telegram->sendMessage($chat, "Welcome, {$sub->first_name}! Tap to join the signals channel:\n".($invite['invite_link'] ?? ''));
    }

    /** @param array<string, mixed> $req */
    private function joinRequest(array $req): void
    {
        $userId = (int) data_get($req, 'from.id', 0);
        $chatId = data_get($req, 'chat.id');
        if ((string) $chatId !== (string) config('services.telegram.channel_id')) {
            return;
        }
        $sub = $this->entitledFor($userId);
        if ($sub) {
            $this->telegram->call('approveChatJoinRequest', ['chat_id' => $chatId, 'user_id' => $userId]);
            $sub->update(['joined_at' => $sub->joined_at ?? now(), 'status' => 'active']);
        } else {
            $this->telegram->call('declineChatJoinRequest', ['chat_id' => $chatId, 'user_id' => $userId]);
            Activity::log('signals.join_declined', null, ['telegram_user_id' => $userId]);
        }
    }

    private function entitledFor(int $telegramUserId): ?SignalSubscription
    {
        return SignalSubscription::query()->with('user.membership')->where('telegram_user_id', $telegramUserId)->get()
            ->first(fn (SignalSubscription $s) => $s->isEntitled());
    }
}
