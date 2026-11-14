<?php

namespace App\Console\Commands;

use App\Signals\SignalAccess;
use Illuminate\Console\Command;

class SignalsSweep extends Command
{
    protected $signature = 'signals:sweep';

    protected $description = 'Remove people from the signals channel when their access (incl. grace) has ended.';

    public function handle(SignalAccess $access): int
    {
        $this->info('Removed: '.$access->sweep());

        return self::SUCCESS;
    }
}
