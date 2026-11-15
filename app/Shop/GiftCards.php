<?php

namespace App\Shop;

use App\Jobs\SendCustomerMessage;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Models\Cart;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Payment;
use App\Notify\Message;
use App\Payments\PaymentService;
use App\Support\Activity;
use App\Support\Currency;
use App\Support\Features;
use App\Support\Money;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Gift cards: bought online (receipt) or sold in the shop by staff. Each code
 * is 16 random characters (about 79 bits), shown once and stored only as a
 * keyed hash. Spending one is a payment on the order, like the wallet.
 */
class GiftCards
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I

    public function __construct(private readonly Ledger $ledger) {}

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalize($code), (string) config('app.key'));
    }

    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** @return list<int> the amounts people can choose (Settings) */
    public static function amounts(): array
    {
        $list = array_filter(array_map(fn ($v) => (int) round(((float) trim($v)) * 100), explode(',', Settings::string('giftcards.amounts'))), fn ($v) => $v >= 10_000);

        return array_values(array_unique($list));
    }

    /** @param array{amount:int, buyer_name:string, buyer_phone:string, buyer_email?:?string, recipient_name?:?string, recipient_contact?:?string, message?:?string} $d */
    public function create(array $d): GiftCard
    {
        if (! in_array($d['amount'], self::amounts(), true)) {
            throw new InvalidArgumentException('Choose one of the amounts.');
        }
        $phone = Phone::normalize($d['buyer_phone']) ?? throw new InvalidArgumentException('Enter your phone number (09…).');
        $contact = isset($d['recipient_contact']) && trim((string) $d['recipient_contact']) !== '' ? trim((string) $d['recipient_contact']) : null;
        if ($contact !== null && ! filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $contact = Phone::normalize($contact) ?? throw new InvalidArgumentException('Type their phone number (09…) or email.');
        }

        return GiftCard::query()->create(['amount' => $d['amount'], 'status' => 'pending', 'buyer_name' => strip_tags($d['buyer_name']),
            'buyer_phone' => $phone, 'buyer_email' => $d['buyer_email'] ?? null,
            'recipient_name' => isset($d['recipient_name']) ? strip_tags((string) $d['recipient_name']) : null,
            'recipient_contact' => $contact, 'message' => isset($d['message']) ? strip_tags((string) $d['message']) : null]);
    }

    /** Paid (receipt approved): make the code and send it. Returns the code (only this once). */
    public function activate(GiftCard $card): string
    {
        $code = $this->newCode();
        $days = Settings::int('giftcards.valid_days');
        $card->forceFill(['code_hash' => self::hash($code), 'last4' => substr($code, -4), 'balance' => $card->amount, 'status' => 'active',
            'paid_at' => now(), 'expires_at' => $days > 0 ? now()->addDays($days) : null])->save();
        $pretty = implode('-', str_split($code, 4));
        $site = Settings::string('site.name');
        $until = $card->expires_at ? ' Use it before '.$card->expires_at->timezone(config('app.timezone'))->format('d M Y').'.' : '';
        $to = $card->recipient_contact ?? $card->buyer_phone;
        $isEmail = $to !== null && str_contains($to, '@');
        $body = ($card->recipient_name ? "Hi {$card->recipient_name}! {$card->buyer_name} sent you" : 'Your').' a '.$site.' gift card of '
            .Money::format($card->amount).($card->message ? " “{$card->message}”" : '').". Code: $pretty — type it at checkout.".$until.' '.url('/shop');
        DB::afterCommit(fn () => SendCustomerMessage::dispatch(new Message($site.' gift card', $body,
            $isEmail ? null : $to, $isEmail ? $to : $card->buyer_email, type: 'gift_card')));

        return $code;
    }

    /**
     * Staff sold a card in the shop (cash in hand): active right away. Returns the code to give to the buyer.
     *
     * @return array{0: GiftCard, 1: string}
     */
    public function sellInShop(int $amount, string $buyerName, ?string $buyerPhone, int $actorId): array
    {
        if ($amount < 10_000) {
            throw new InvalidArgumentException('A gift card is at least 100 ETB.');
        }

        return DB::transaction(function () use ($amount, $buyerName, $buyerPhone, $actorId) {
            $card = GiftCard::query()->create(['amount' => $amount, 'status' => 'pending', 'buyer_name' => strip_tags($buyerName),
                'buyer_phone' => Phone::normalize($buyerPhone), 'created_by' => $actorId]);
            $this->ledger->post('giftcard.sold', [[Account::CASH, $amount], [GiftCard::ACCOUNT, -$amount]], "giftcard:{$card->id}:sold", $card,
                actorId: $actorId, description: 'Gift card sold in the shop');
            $code = $this->activate($card);
            Activity::log('giftcard.sold_in_shop', $card, ['amount' => Money::format($amount)], null, $actorId);

            return [$card->fresh() ?? $card, $code];
        });
    }

    /** Type a code in the cart. Slow on purpose for guessing: 5 tries per 10 minutes per cart and per address. */
    public function attach(Cart $cart, string $code, string $ip): GiftCard
    {
        foreach (['giftcard:cart:'.$cart->id, 'giftcard:ip:'.$ip] as $key) {
            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw new InvalidArgumentException('Too many tries. Please wait a few minutes.');
            }
            RateLimiter::hit($key, 600);
        }
        $card = GiftCard::query()->where('code_hash', self::hash($code))->first();
        if (! $card || ! $card->usable()) {
            throw new InvalidArgumentException($card && $card->balance === 0 ? 'This gift card is used up.' : 'This gift card code is not valid.');
        }
        $cart->update(['gift_card_id' => $card->id]);

        return $card;
    }

    /** At checkout: pay as much of the order as the card covers (locked, so two orders can't spend the same money). */
    public function apply(Order $order, int $cardId): ?Payment
    {
        if (Features::disabled('shop.gift_cards')) {
            return null;
        }
        $done = DB::transaction(function () use ($order, $cardId) {
            $card = GiftCard::query()->whereKey($cardId)->lockForUpdate()->first();
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $card || ! $card->usable() || $locked->isSettled()) {
                return null;
            }
            $amount = min($card->balance, $locked->amountDue());
            if ($amount < 1) {
                return null;
            }
            $card->update(['balance' => $card->balance - $amount, 'status' => $card->balance - $amount === 0 ? 'used' : 'active']);
            $this->ledger->post('giftcard.spent', [[GiftCard::ACCOUNT, $amount], [$locked->payableIncomeAccount(), -$amount]],
                "giftcard:{$card->id}:order:{$locked->id}", $locked, description: 'Paid with gift card …'.$card->last4);
            $remaining = $locked->amountDue() - $amount;
            $payment = Payment::query()->create([
                'uuid' => (string) Str::uuid(), 'payable_type' => $locked->getMorphClass(), 'payable_id' => $locked->id,
                'user_id' => $locked->user_id, 'provider' => Payment::GIFT_CARD, 'tx_ref' => 'KMG-'.Str::upper(Str::random(18)),
                'amount' => $amount, 'currency' => Currency::code(),
                'status' => $remaining > 0 ? Payment::PAID : Payment::PENDING, 'paid_at' => $remaining > 0 ? now() : null,
                'meta' => ['gift_card_id' => $card->id],
            ]);
            $locked->forceFill(['gift_card_used' => $locked->gift_card_used + $amount])->save();

            return [$payment, $remaining];
        });
        if ($done === null) {
            return null;
        }
        [$payment, $remaining] = $done;
        if ($remaining === 0) {
            app(PaymentService::class)->markPaid($payment);
        }

        return $payment;
    }

    /** An unpaid order was cancelled: what the card paid goes back on it. */
    public function releaseFor(Order $order, string $reason): void
    {
        Payment::query()->where('payable_type', $order->getMorphClass())->where('payable_id', $order->getKey())
            ->where('provider', Payment::GIFT_CARD)->whereIn('status', [Payment::PAID, Payment::PARTIALLY_REFUNDED])
            ->each(fn (Payment $p) => app(PaymentService::class)->refund($p, $p->refundable(), $reason));
    }

    /** A refund of a gift-card payment goes back on the card. */
    public function refundToCard(Payment $payment, int $amount): void
    {
        $card = GiftCard::query()->whereKey((int) ($payment->meta['gift_card_id'] ?? 0))->lockForUpdate()->first();
        if ($card) {
            $card->update(['balance' => $card->balance + $amount, 'status' => in_array($card->status, ['used', 'active'], true) ? 'active' : $card->status]);
        }
    }

    private function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 16; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (GiftCard::query()->where('code_hash', self::hash($code))->exists());

        return $code;
    }
}
