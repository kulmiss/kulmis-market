<?php

namespace App\Notify;

use App\Support\Features;

/** One message to one customer, over whichever channels they have. */
final class Message
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?int $telegramChatId = null,
        /** Message type ("order_paid"…): its switch is "messages.<type>". */
        public readonly ?string $type = null,
    ) {}

    /** False when the Super Admin turned this message type off. */
    public function allowed(): bool
    {
        $key = 'messages.'.$this->type;

        return $this->type === null || ! Features::exists($key) || Features::enabled($key);
    }
}
