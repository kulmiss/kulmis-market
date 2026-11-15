<?php

namespace App\Notify\Sms;

use Illuminate\Support\Facades\Log;

/** Default until an SMS provider account is set up. */
class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): void
    {
        Log::channel(config('logging.default'))->info('[SMS] to '.$to.': '.$message);
    }
}
