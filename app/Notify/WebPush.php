<?php

namespace App\Notify;

use App\Models\PushSubscription;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Minimal Web Push (RFC 8030 + VAPID, RFC 8292) without a message body:
 * the phone only learns "there is news" and then asks our site (signed in)
 * what it is. So no money amounts travel through Google/Apple/Mozilla, and
 * nothing private is stored on the phone.
 */
class WebPush
{
    /** Only real push services: the address comes from the browser, so never call anything else (SSRF). */
    public const HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com',
        'push.services.mozilla.com', 'web.push.apple.com', 'api.push.apple.com', 'notify.windows.com', 'wns2-*.notify.windows.com'];

    public static function configured(): bool
    {
        return self::publicKey() !== '' && (string) config('services.webpush.private_key') !== '';
    }

    public static function publicKey(): string
    {
        return (string) config('services.webpush.public_key');
    }

    public static function allowedEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port']) || strlen($url) > 1000) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || fnmatch($allowed, $host) || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** @return bool false when the subscription is gone (we then delete it) */
    public function send(PushSubscription $sub): bool
    {
        if (! self::configured() || ! self::allowedEndpoint($sub->endpoint)) {
            return false;
        }
        $p = parse_url($sub->endpoint);
        $audience = 'https://'.$p['host'];
        try {
            $res = Http::timeout(5)->withHeaders([
                'Authorization' => 'vapid t='.$this->jwt($audience).', k='.self::publicKey(),
                'TTL' => '86400', 'Urgency' => 'normal', 'Topic' => 'km-news',
            ])->withBody('', 'application/octet-stream')->post($sub->endpoint);
        } catch (Throwable $e) {
            Log::info('Push failed: '.$e->getMessage());
            $sub->increment('failures');

            return true;
        }
        if (in_array($res->status(), [404, 410], true)) {
            return false;
        }
        $res->successful() ? $sub->forceFill(['last_used_at' => now(), 'failures' => 0])->save() : $sub->increment('failures');

        return $sub->failures < 20;
    }

    public function jwt(string $audience): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $subject = (string) config('services.webpush.subject') ?: 'mailto:'.Settings::string('site.support_email');
        $data = $b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'
            .$b64((string) json_encode(['aud' => $audience, 'exp' => time() + 43200, 'sub' => $subject]));
        $key = openssl_pkey_get_private((string) base64_decode((string) config('services.webpush.private_key'), true));
        if ($key === false || ! openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Web Push key is not valid. Run php artisan km:push-keys.');
        }

        return $data.'.'.$b64(self::derToRaw($der));
    }

    /** ECDSA signature: DER (what OpenSSL gives) → 64 raw bytes (what JWT wants). */
    public static function derToRaw(string $der): string
    {
        $pos = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $out .= str_pad(ltrim($int, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $pos += 2 + $len;
        }

        return $out;
    }

    /** @return array{public:string, private:string} new VAPID keys */
    public static function newKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('OpenSSL could not make a key.');
        }
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key);
        $point = "\x04".str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT).str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        return ['public' => rtrim(strtr(base64_encode($point), '+/', '-_'), '='), 'private' => base64_encode((string) $pem)];
    }
}
