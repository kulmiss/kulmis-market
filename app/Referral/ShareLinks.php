<?php

namespace App\Referral;

use App\Models\User;

/**
 * Personal links: /r/CODE goes to the home page, /r/CODE/membership,
 * /r/CODE/signals and /r/CODE/shop/<product> to those pages. Buying after
 * any of them counts as a normal referral sale (the code is remembered).
 */
final class ShareLinks
{
    /** Where a share link may lead (never an outside site). */
    public static function target(string $to): ?string
    {
        return match (true) {
            $to === '' => '/',
            $to === 'membership' => '/membership',
            $to === 'signals' => '/signals',
            (bool) preg_match('#^shop/[a-z0-9-]{1,160}$#', $to) => '/'.$to,
            default => null,
        };
    }

    public static function for(User $user, string $to = ''): string
    {
        return url('/r/'.$user->referral_code.($to !== '' ? '/'.$to : ''));
    }

    /** @return array{whatsapp:string, telegram:string, facebook:string} */
    public static function buttons(string $url, string $text): array
    {
        return [
            'whatsapp' => 'https://wa.me/?text='.rawurlencode($text.' '.$url),
            'telegram' => 'https://t.me/share/url?url='.rawurlencode($url).'&text='.rawurlencode($text),
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($url),
        ];
    }
}
