<?php

namespace App\Jobs;

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

    public function __construct(public Message $message, public ?int $statusChangeId = null) {}

    public function handle(SmsGateway $sms, TelegramClient $telegram): void
    {
        $sent = [];
        $m = $this->message;
        if ($m->phone && Features::enabled('notifications.sms')) {
            $this->attempt('sms', fn () => $sms->send($m->phone, $m->body), $sent);
        }
        if ($m->email && Features::enabled('notifications.email')) {
            $this->attempt('email', fn () => Mail::to($m->email)->send(new PlainMessage($m->subject, $m->body)), $sent);
        }
        if ($m->telegramChatId && Features::enabled('notifications.telegram') && $telegram->configured()) {
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
