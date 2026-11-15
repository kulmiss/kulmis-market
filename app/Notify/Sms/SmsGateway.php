<?php

namespace App\Notify\Sms;

interface SmsGateway
{
    public function send(string $to, string $message): void;
}
