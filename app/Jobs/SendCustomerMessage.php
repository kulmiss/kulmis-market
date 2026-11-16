<?php

namespace App\Jobs;

use App\Demo\Demo;
use App\Mail\PlainMessage;
use App\Models\OrderStatusChange;
use App\Notify\Message;
use App\Notify\Sms\SmsGateway;
use App\Support\Features;
use App\Telegram\TelegramClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends one message by SMS, email and Telegram (each switchable). */
class SendCustomerMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /** $security: account-safety notices ignore the marketing/notification switches. */
    public function __construct(public Message $message, public ?int $statusChangeId = null, public bool $security = false) {}

    public function handle(SmsGateway $sms, TelegramClient $telegram): void
    {
        $sent = [];
        $m = $this->message;
        if (Demo::isDemoContact($m->phone, $m->email)) {
            Log::info('Message to demo contact not sent: '.$m->subject); // demo phones (+251900…) and *.test emails

            return;
        }
        $security = $this->security || Message::isAccountSafety($m->type); // account safety: always, every channel
        if (! $m->featureOn() || (! $security && ! $m->allowed())) {
            Log::info('Message type switched off, not sent: '.$m->type);
            if ($this->statusChangeId) {
                OrderStatusChange::query()->whereKey($this->statusChangeId)->update(['notified' => json_encode(['all' => 'off'])]);
            }

            return;
        }
        if ($m->phone && ($security || Features::enabled('notifications.sms'))) {
            $this->attempt('sms', fn () => $sms->send($m->phone, $m->body), $sent);
        }
        if ($m->email && ($security || Features::enabled('notifications.email'))) {
            $this->attempt('email', fn () => Mail::to($m->email)->send(new PlainMessage($m->subject, $m->body)), $sent);
        }
        if ($m->telegramChatId && ($security || Features::enabled('notifications.telegram')) && $telegram->configured()) {
            $this->attempt('telegram', fn () => $telegram->sendMessage($m->telegramChatId, e($m->body)), $sent);
        }
        if ($this->statusChangeId) {
            OrderStatusChange::query()->whereKey($this->statusChangeId)->update(['notified' => json_encode($sent)]);
        }
    }

    /** @param array<string, string> $sent */
    private function attempt(string $channel, callable $send, array &$sent): void
    {
        try {
            $send();
            $sent[$channel] = 'sent';
        } catch (Throwable $e) {
            // One failing channel must not block the others.
            Log::warning("Customer message via $channel failed: ".$e->getMessage());
            $sent[$channel] = 'failed';
        }
    }
}
