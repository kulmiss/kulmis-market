<?php

namespace App\Notify;

use App\Support\Features;

/** One message to one customer, over whichever channels they have. */
final class Message
{
    /**
     * The feature a message type belongs to (a type prefix => feature). When
     * that feature is switched off the message is never sent, not even a
     * security notice: the feature "never existed".
     */
    public const FEATURES = [
        'order_' => 'shop', 'back_in_stock' => 'shop', 'cart_reminder' => 'shop', 'winback' => 'shop',
        'membership_' => 'membership', 'loyalty_reward' => 'loyalty_reward', 'signals_access' => 'signals',
        'badge_unlocked' => 'badges', 'badge_bonus' => 'badges.bonus',
    ];

    /**
     * Account-safety messages (type prefixes): new sign-in, password, email,
     * payout account, two-step changes, cash-outs. Always sent, on every
     * channel the person has, whatever any switch says.
     */
    public const ALWAYS_SENT = ['security_', 'payout_'];

    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?int $telegramChatId = null,
        /** Message type ("order_paid"…): its switch is "messages.<type>". */
        public readonly ?string $type = null,
    ) {}

    public static function isAccountSafety(?string $type): bool
    {
        foreach (self::ALWAYS_SENT as $prefix) {
            if ($type !== null && str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** The switch a message type needs, or null. */
    public static function featureOf(?string $type): ?string
    {
        foreach (self::FEATURES as $prefix => $feature) {
            if ($type !== null && str_starts_with($type, $prefix)) {
                return $feature;
            }
        }

        return null;
    }

    /** False when the message's feature is switched off. Security notices too. */
    public function featureOn(): bool
    {
        if (self::isAccountSafety($this->type)) {
            return true;
        }
        $f = self::featureOf($this->type);

        return $f === null || Features::enabled($f);
    }

    /** False when the Super Admin turned this message type off. */
    public function allowed(): bool
    {
        $key = 'messages.'.$this->type;

        return $this->type === null || ! Features::exists($key) || Features::enabled($key);
    }
}
