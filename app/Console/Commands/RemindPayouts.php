<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use App\Wallet\CashOuts;
use Illuminate\Console\Command;

class RemindPayouts extends Command
{
    protected $signature = 'payouts:remind';

    protected $description = 'Remind Super Admin about cash-outs due within a day or overdue.';

    public function handle(CashOuts $cashOuts, TelegramClient $telegram): int
    {
        $this->info('Reminded about: '.$cashOuts->remindAdmin($telegram));

        return self::SUCCESS;
    }
}
