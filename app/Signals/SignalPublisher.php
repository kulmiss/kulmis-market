<?php

namespace App\Signals;

use App\Models\Signal;
use App\Telegram\TelegramClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Posts signals and their results to the private channel. */
class SignalPublisher
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function post(Signal $signal): void
    {
        $id = $this->send(self::format($signal));
        if ($id) {
            $signal->update(['telegram_message_id' => $id, 'posted_at' => now()]);
        } else {
            $signal->update(['posted_at' => now()]);
        }
    }

    public function postResult(Signal $signal): void
    {
        $text = '<b>Result: '.e($signal->pair).' '.strtoupper(e($signal->direction)).'</b> → '.strtoupper((string) $signal->result)
            .($signal->result_value ? ' ('.e($signal->result_value).')' : '').($signal->result_note ? "\n".e($signal->result_note) : '');
        $this->send($text, $signal->telegram_message_id);
    }

    public static function format(Signal $s): string
    {
        $lines = ['<b>'.e($s->pair).' — '.strtoupper(e($s->direction)).'</b>', 'Entry: '.e($s->entry)];
        foreach (array_values($s->targets) as $i => $t) {
            $lines[] = 'Target '.($i + 1).': '.e($t);
        }
        $lines[] = 'Stop loss: '.e($s->stop_loss);
        if ($s->notes) {
            $lines[] = '';
            $lines[] = e($s->notes);
        }

        return implode("\n", $lines);
    }

    private function send(string $text, ?int $replyTo = null): ?int
    {
        $channel = config('services.telegram.channel_id');
        if (! $this->telegram->configured() || ! $channel) {
            return null;
        }
        try {
            $res = $this->telegram->sendMessage($channel, $text, $replyTo ? ['reply_parameters' => ['message_id' => $replyTo, 'allow_sending_without_reply' => true]] : []);

            return isset($res['message_id']) ? (int) $res['message_id'] : null;
        } catch (Throwable $e) {
            Log::warning('Posting to Telegram failed: '.$e->getMessage());

            return null;
        }
    }
}
