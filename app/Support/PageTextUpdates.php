<?php

namespace App\Support;

/**
 * The first texts of the terms, privacy and rules pages, with {if feature}
 * parts, so a switched-off feature leaves no sentence behind. Each rule turns
 * one original sentence (a regex: numbers and amounts may differ) into the
 * new one. Used by the pages seeder (new shops) and a migration (shops that
 * still have the original sentence; a sentence you changed is left alone).
 */
final class PageTextUpdates
{
    /** @var array<string, string> regex => replacement */
    public const RULES = [
        '/ sells handcrafted products made in Ethiopia and offers a trading-signals service and a paid membership\./' => ' sells handcrafted products made in Ethiopia.{if signals} It also offers a trading-signals service.{/if}{if membership} It has a paid monthly membership.{/if}',
        '/send us the receipt, or pay from your wallet balance\./' => 'send us the receipt{if wallet.spend}, or pay from your wallet balance{/if}.',
        '/We use only two essential cookies: one for your cart and one that remembers a referral code for (\S+) days\./' => 'We use only essential cookies: one for your cart{if referral} and one that remembers a referral code for $1 days{/if}.',
        '/send order and renewal messages \(SMS, email, Telegram\), run the membership and referral program, prevent fraud/' => 'send order{if membership} and renewal{/if} messages (SMS, email, Telegram),{if membership} run the membership,{/if}{if referral} run the referral program,{/if} prevent fraud',
        '/- Refunds go back the way you paid: by transfer, or to your wallet if you paid from it\./' => '- Refunds go back the way you paid{if wallet}: by transfer, or to your wallet if you paid from it{/if}.',
        '/## Membership and signals\n- A membership or signals month can be refunded/' => "## Membership{if signals} and signals{/if}\n- A membership{if signals} or signals{/if} month can be refunded",
        '/After that, discounts, signals access and referral earnings stop until you renew\. Your wallet balance stays yours\. Earnings that would have been paid to you while you were not active are not paid later\./' => 'After that, your benefits stop until you renew.{if wallet} Your wallet balance stays yours.{/if}{if referral} Earnings that would have been paid to you while you were not active are not paid later.{/if}',
        '/## Using and cashing out\nUse your available balance in the shop or for your membership, or cash out to Telebirr or a bank account from (\*\*[^*]+\*\*), any time\. The account name must match your name\. We pay within (\S+) days\./' => "## Using your earnings\n{if wallet.spend}Use your available balance{if shop} in the shop{/if}{if shop}{if membership} or{/if}{/if}{if membership} for your membership{/if}.{/if}{if wallet.cashout} Cash out to Telebirr or a bank account from $1, any time. The account name must match your name. We pay within $2 days.{/if}{if !wallet.spend}{if !wallet.cashout}Your earnings are kept in your account.{/if}{/if}",
        '/earnings and cash-outs can be held during a review/' => 'earnings{if wallet.cashout} and cash-outs{/if} can be held during a review',
        "/You never see phones, emails, addresses, purchases or other members' wallets\./" => "You never see phones, emails, addresses{if wallet}, purchases or other members' wallets{/if}{if !wallet} or purchases{/if}.",
    ];

    public static function apply(string $body): string
    {
        foreach (self::RULES as $from => $to) {
            $body = (string) preg_replace($from, $to, $body);
        }

        return $body;
    }
}
