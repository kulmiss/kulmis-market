<?php

namespace App\Support;

/**
 * The shop currency and how amounts are written (Super Admin → Settings →
 * General). Money is always stored as integer minor units (1/100), so
 * changing the currency only changes how amounts are SHOWN: nothing is
 * converted.
 */
final class Currency
{
    public const POSITIONS = ['after_space', 'after', 'before_space', 'before'];

    public const DECIMALS = ['auto', '0', '2'];

    /** "none" = no thousands separator. */
    public const THOUSANDS = [',', '.', 'space', "'", 'none'];

    public const DECIMAL_POINTS = ['.', ','];

    /** @var array{code:string,symbol:string,position:string,decimals:string,thousands:string,decimal_point:string}|null */
    private static ?array $memo = null;

    public static function code(): string
    {
        return self::config()['code'];
    }

    /** @return array{code:string,symbol:string,position:string,decimals:string,thousands:string,decimal_point:string} */
    public static function config(): array
    {
        return self::$memo ??= [
            'code' => Settings::string('currency.code'),
            'symbol' => Settings::string('currency.symbol'),
            'position' => Settings::string('currency.position'),
            'decimals' => Settings::string('currency.decimals'),
            'thousands' => Settings::string('currency.thousands'),
            'decimal_point' => Settings::string('currency.decimal_point'),
        ];
    }

    /** What clients (storefront JS, admin, app) need to format amounts the same way. */
    public static function forClients(): array
    {
        $c = self::config();

        return $c + ['thousands_char' => self::thousandsChar($c['thousands']), 'minor_units' => 100];
    }

    public static function flush(): void
    {
        self::$memo = null;
    }

    /** Format minor units (santim, cents…) as "5,000 ETB", "$1,234.50", "1.234,50 €". */
    public static function format(int $minor, bool $withSymbol = true): string
    {
        $c = self::config();
        $abs = abs($minor);
        $fraction = $abs % 100;
        $decimals = match ($c['decimals']) {
            '0' => 0,
            '2' => 2,
            default => $fraction ? 2 : 0,
        };
        $number = number_format($abs / 100, $decimals, $c['decimal_point'], self::thousandsChar($c['thousands']));
        $sign = $minor < 0 ? '-' : '';
        if (! $withSymbol) {
            return $sign.$number;
        }

        return $sign.match ($c['position']) {
            'after' => $number.$c['symbol'],
            'before_space' => $c['symbol'].' '.$number,
            'before' => $c['symbol'].$number,
            default => $number.' '.$c['symbol'],
        };
    }

    /** Example used next to the settings so the admin sees the result before saving. */
    public static function example(): string
    {
        return self::format(123_456_789);
    }

    private static function thousandsChar(string $thousands): string
    {
        return match ($thousands) {
            'space' => "\u{00A0}",
            'none' => '',
            default => $thousands,
        };
    }
}
