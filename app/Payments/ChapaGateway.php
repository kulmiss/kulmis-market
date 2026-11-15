<?php

namespace App\Payments;

use App\Models\Payment;
use App\Support\Currency;
use App\Support\Phone;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chapa (Telebirr, CBE Birr, M-Pesa, cards and more).
 * Docs: https://developer.chapa.co
 */
class ChapaGateway
{
    public function configured(): bool
    {
        return (string) config('services.chapa.secret_key') !== '';
    }

    /** @return list<string> */
    public function currencies(): array
    {
        return array_values(array_map(fn ($c) => strtoupper(trim((string) $c)), (array) config('services.chapa.currencies', ['ETB'])));
    }

    public function supports(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->currencies(), true);
    }

    /** Shown wherever Chapa would be offered when the shop currency isn't supported. */
    public function unsupportedMessage(): string
    {
        return 'Online payment through Chapa only works in '.implode(', ', $this->currencies()).', and this shop now uses '
            .Currency::code().'. Please pay by bank or Telebirr transfer, or contact us.';
    }

    /**
     * @param  array{first_name:string,last_name:string,phone:?string,email:?string}  $customer
     * @return string checkout URL
     */
    public function initialize(Payment $payment, array $customer, string $title, string $returnUrl, string $callbackUrl): string
    {
        $phone = $customer['phone'] ? '0'.substr((string) Phone::normalize($customer['phone']), 4) : null;
        $res = $this->http()->post($this->base().'/transaction/initialize', array_filter([
            'amount' => self::birr($payment->amount),
            'currency' => $payment->currency,
            'email' => $customer['email'],
            'first_name' => $customer['first_name'],
            'last_name' => $customer['last_name'] ?: $customer['first_name'],
            'phone_number' => $phone,
            'tx_ref' => $payment->tx_ref,
            'callback_url' => $callbackUrl,
            'return_url' => $returnUrl,
            'customization' => ['title' => mb_substr(config('app.name'), 0, 16), 'description' => mb_substr($title, 0, 50)],
        ], fn ($v) => $v !== null && $v !== ''));

        $url = $res->json('data.checkout_url');
        if (! $res->successful() || ! is_string($url)) {
            throw new RuntimeException('Chapa could not start the payment: '.json_encode($res->json('message')));
        }

        return $url;
    }

    /**
     * Ask Chapa for the real status. Never trust a webhook or redirect alone.
     *
     * @return array{status:string, amount:int, currency:string, reference:?string, charge:int, payer:?string, raw:array<string,mixed>}
     */
    public function verify(string $txRef): array
    {
        $res = $this->http()->get($this->base().'/transaction/verify/'.rawurlencode($txRef));
        $data = (array) $res->json('data');
        if (! $res->successful() || $data === []) {
            return ['status' => 'unknown', 'amount' => 0, 'currency' => '', 'reference' => null, 'charge' => 0, 'payer' => null, 'raw' => (array) $res->json()];
        }

        return [
            // Chapa must confirm the same transaction we asked about.
            'status' => isset($data['tx_ref']) && (string) $data['tx_ref'] !== $txRef ? 'mismatch' : (string) ($data['status'] ?? 'unknown'),
            'amount' => self::santim((string) ($data['amount'] ?? '0')),
            'currency' => (string) ($data['currency'] ?? ''),
            'reference' => isset($data['reference']) ? (string) $data['reference'] : null,
            'charge' => self::santim((string) ($data['charge'] ?? '0')),
            'payer' => isset($data['mobile']) ? (string) $data['mobile'] : (isset($data['phone_number']) ? (string) $data['phone_number'] : null),
            'raw' => array_intersect_key($data, array_flip(['status', 'amount', 'currency', 'reference', 'charge', 'method', 'tx_ref', 'created_at'])),
        ];
    }

    /** Refund through Chapa's refund API. */
    public function refund(Payment $payment, int $amount, string $reason): ?string
    {
        $res = $this->http()->asForm()->post($this->base().'/refund/'.rawurlencode($payment->tx_ref), [
            'reason' => mb_substr($reason, 0, 200), 'amount' => self::birr($amount),
            'reference' => $payment->uuid.'-'.now()->timestamp,
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('Chapa refund failed: '.json_encode($res->json('message')));
        }

        return $res->json('data.reference') ? (string) $res->json('data.reference') : null;
    }

    /**
     * Webhook check: x-chapa-signature = HMAC-SHA256(payload, secret) or
     * chapa-signature = HMAC-SHA256(secret, secret). Constant-time compare.
     */
    public function validSignature(string $rawBody, ?string $xSignature, ?string $signature): bool
    {
        $secret = (string) config('services.chapa.webhook_secret');
        if ($secret === '') {
            return false;
        }
        if ($xSignature && hash_equals(hash_hmac('sha256', $rawBody, $secret), $xSignature)) {
            return true;
        }

        return $signature !== null && hash_equals(hash_hmac('sha256', $secret, $secret), $signature);
    }

    public static function birr(int $santim): string
    {
        return intdiv($santim, 100).'.'.str_pad((string) ($santim % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function santim(string $amount): int
    {
        $amount = str_replace(',', '', trim($amount));
        if (! preg_match('/^\d+(\.\d+)?$/', $amount)) {
            return 0;
        }
        [$w, $f] = array_pad(explode('.', $amount), 2, '0');

        return (int) $w * 100 + (int) str_pad(substr($f, 0, 2), 2, '0');
    }

    private function base(): string
    {
        return rtrim((string) config('services.chapa.base_url', 'https://api.chapa.co/v1'), '/');
    }

    private function http(): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('CHAPA_SECRET_KEY is not configured.');
        }

        return Http::withToken((string) config('services.chapa.secret_key'))->acceptJson()->timeout(20);
    }
}
