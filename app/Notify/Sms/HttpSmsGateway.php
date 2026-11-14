<?php

namespace App\Notify\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Generic JSON SMS API: POST {to, message, sender} with a Bearer token.
 * Most Ethiopian SMS providers offer this shape; adjust here if yours differs.
 */
class HttpSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): void
    {
        $url = (string) config('services.sms.url');
        if ($url === '') {
            throw new RuntimeException('SMS_URL is not configured.');
        }
        $res = Http::withToken((string) config('services.sms.token'))->acceptJson()->timeout(15)
            ->post($url, ['to' => $to, 'message' => $message, 'sender' => config('services.sms.sender')]);
        if ($res->failed()) {
            throw new RuntimeException('SMS provider error: HTTP '.$res->status());
        }
    }
}
