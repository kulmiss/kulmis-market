<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money helpers. Amounts are always integer santim (1 ETB = 100 santim).
 *
 * Rounding rules (see QUESTIONS.md):
 * - Customer discounts round half-up to the santim (Money::bpsRound).
 * - Commissions and rewards round down (Money::bpsFloor), so the shop never
 *   pays out a fraction it did not receive.
 */
final class Money
{
    public static function bpsFloor(int $amount, int $bps): int
    {
        self::assertBps($bps);

        return intdiv($amount * $bps, 10_000);
    }

    public static function bpsRound(int $amount, int $bps): int
    {
        self::assertBps($bps);

        return intdiv($amount * $bps + 5_000, 10_000);
    }

    /** "5,000 ETB" / "1,234.50 ETB" */
    public static function format(int $santim, bool $withCurrency = true): string
    {
        $neg = $santim < 0;
        $abs = abs($santim);
        $birr = number_format(intdiv($abs, 100));
        $cents = $abs % 100;
        $text = ($neg ? '-' : '').$birr.($cents ? '.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT) : '');

        return $withCurrency ? $text.' '.config('shop.currency', 'ETB') : $text;
    }

    /** Parse "1,234.5" or 1234 (birr) into santim. */
    public static function fromBirr(string|int $birr): int
    {
        if (is_int($birr)) {
            return $birr * 100;
        }
        $clean = str_replace([',', ' '], '', trim($birr));
        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $clean)) {
            throw new InvalidArgumentException("Invalid amount [$birr].");
        }
        [$whole, $frac] = array_pad(explode('.', ltrim($clean, '-')), 2, '0');
        $value = (int) $whole * 100 + (int) str_pad($frac, 2, '0');

        return str_starts_with($clean, '-') ? -$value : $value;
    }

    private static function assertBps(int $bps): void
    {
        if ($bps < 0 || $bps > 10_000) {
            throw new InvalidArgumentException("Basis points out of range [$bps].");
        }
    }
}
