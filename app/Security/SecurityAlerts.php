<?php

namespace App\Security;

use App\Jobs\SendCustomerMessage;
use App\Models\User;
use App\Notify\Message;
use App\Support\Activity;
use App\Support\Settings;
use App\Telegram\TelegramClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Was this you?" messages to the account owner, and critical alerts to the
 * shop owner on Telegram. Every alert is also written to the activity log as
 * security.<event>.
 */
class SecurityAlerts
{
    public const EVENTS = ['new_sign_in', 'password_changed', 'email_change_requested', 'email_changed', 'payout_account_changed',
        'two_factor_on', 'two_factor_off', 'locked', 'signed_out_everywhere'];

    public function __construct(private readonly TelegramClient $telegram) {}

    /** @param array<string, mixed> $details  shown as {placeholders} in the text */
    public function notify(User $user, string $event, array $details = [], ?string $toEmail = null): void
    {
        Activity::log('security.'.$event, $user, array_diff_key($details, ['link' => 1]), null, $user->id);
        $text = strtr(Settings::string('notify.security_'.$event), array_merge([
            '{name}' => $user->first_name, '{site}' => Settings::string('site.name'),
            '{when}' => now()->timezone(config('app.timezone'))->format('d M Y H:i'),
            '{secure_url}' => url('/account/security'),
        ], collect($details)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => is_scalar($v) ? (string) $v : ''])->all()));
        // Security notices go to every channel the person has, including the
        // old email address when the email is being changed.
        SendCustomerMessage::dispatch(new Message('Security notice: '.Settings::string('site.name'), $text,
            $user->phone, $toEmail ?? $user->email, $user->telegram_user_id), null, true);
    }

    /** Owner alert on Telegram (and log) for events that need a human now. */
    public function critical(string $title, array $context = []): void
    {
        Activity::log('security.critical', null, ['title' => $title] + $context);
        Log::warning('SECURITY: '.$title, $context);
        $chat = config('services.telegram.admin_chat_id');
        if (! $chat || ! $this->telegram->configured()) {
            return;
        }
        try {
            $this->telegram->sendMessage($chat, e('⚠️ '.Settings::string('site.name').': '.$title));
        } catch (Throwable $e) {
            Log::warning('Owner alert failed: '.$e->getMessage());
        }
    }
}
