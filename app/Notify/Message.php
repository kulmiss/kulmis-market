<?php

namespace App\Notify;

/** One message to one customer, over whichever channels they have. */
final class Message
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?int $telegramChatId = null,
    ) {}
}
