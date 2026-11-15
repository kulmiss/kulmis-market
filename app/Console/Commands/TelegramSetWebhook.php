<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook {--url= : Defaults to APP_URL/api/v1/webhooks/telegram}';

    protected $description = 'Point the Telegram bot at this site (with the secret token).';

    public function handle(TelegramClient $telegram): int
    {
        $url = (string) ($this->option('url') ?: rtrim((string) config('app.url'), '/').'/api/v1/webhooks/telegram');
        $telegram->call('setWebhook', [
            'url' => $url,
            'secret_token' => (string) config('services.telegram.webhook_secret'),
            'allowed_updates' => ['message', 'chat_join_request'],
            'drop_pending_updates' => true,
        ]);
        $this->info("Webhook set to $url");

        return self::SUCCESS;
    }
}
