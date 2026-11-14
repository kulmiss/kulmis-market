<?php

namespace App\Support;

/** Ethiopian mobile numbers → +2519XXXXXXXX / +2517XXXXXXXX. */
final class Phone
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($d, '251')) {
            $d = substr($d, 3);
        } elseif (str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }

        return preg_match('/^[79]\d{8}$/', $d) ? '+251'.$d : null;
    }

    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    /** "+251 9** *** 678" for screens that must not show the full number. */
    public static function mask(string $phone): string
    {
        return substr($phone, 0, 5).'** *** '.substr($phone, -3);
    }
}
