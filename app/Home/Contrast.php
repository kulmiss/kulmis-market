<?php

namespace App\Home;

/** WCAG contrast for a background colour picked in the builder. */
final class Contrast
{
    public const LIGHT = '#ffffff';

    public const DARK = '#11201a';

    public static function ratio(string $a, string $b): float
    {
        [$l1, $l2] = [self::luminance($a), self::luminance($b)];

        return round((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05), 2);
    }

    /** @return array{0:string, 1:float} the text colour that reads best on $bg, and its contrast */
    public static function best(string $bg): array
    {
        $light = self::ratio($bg, self::LIGHT);
        $dark = self::ratio($bg, self::DARK);

        return $light >= $dark ? [self::LIGHT, $light] : [self::DARK, $dark];
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $c = array_map(function (int $i) use ($hex) {
            $v = hexdec(substr($hex, $i, 2)) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, [0, 2, 4]);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}
