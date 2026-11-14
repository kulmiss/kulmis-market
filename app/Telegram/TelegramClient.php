<?php

namespace App\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Thin Telegram Bot API client. */
class TelegramClient
{
    public function configured(): bool
    {
        return (string) config('services.telegram.bot_token') !== '';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }
        $res = Http::asJson()->timeout(15)->post("https://api.telegram.org/bot{$token}/{$method}", $params);
        $body = (array) $res->json();
        if (! $res->successful() || ! ($body['ok'] ?? false)) {
            throw new RuntimeException("Telegram $method failed: ".($body['description'] ?? 'HTTP '.$res->status()));
        }

        return (array) ($body['result'] ?? []);
    }

    /** @return array<string, mixed> */
    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        return $this->call('sendMessage', array_merge(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML',
            'disable_web_page_preview' => true], $extra));
    }
}
