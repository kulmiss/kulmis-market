<?php

namespace App\Orders;

use App\Support\Features;
use App\Support\Money;
use App\Support\Settings;

/** Paid checkout extras: gift wrapping, express, shipping insurance. All off until you switch them on. */
final class Extras
{
    public const KEYS = ['gift_wrap', 'express', 'insurance'];

    /** @return array<string, array{label:string, hint:string}> the extras that are switched on */
    public static function available(): array
    {
        $out = [];
        foreach (self::KEYS as $k) {
            if (Features::enabled("shop.extras.$k")) {
                $out[$k] = match ($k) {
                    'gift_wrap' => ['label' => 'Gift wrapping', 'hint' => Money::format(Settings::int('extras.gift_wrap_price'))],
                    'express' => ['label' => 'Express: made and sent first', 'hint' => Money::format(Settings::int('extras.express_price'))],
                    'insurance' => ['label' => 'Shipping insurance', 'hint' => rtrim(rtrim(number_format(Settings::int('extras.insurance_bps') / 100, 2), '0'), '.').'% of the goods'],
                };
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $chosen
     * @return list<array{key:string, label:string, amount:int}>
     */
    public static function price(array $chosen, int $merchandise): array
    {
        $lines = [];
        foreach (array_intersect(self::KEYS, $chosen) as $k) {
            if (Features::disabled("shop.extras.$k")) {
                continue; // switched off since it was chosen
            }
            $amount = match ($k) {
                'gift_wrap' => Settings::int('extras.gift_wrap_price'),
                'express' => Settings::int('extras.express_price'),
                'insurance' => max(Settings::int('extras.insurance_min'), Money::bpsRound($merchandise, Settings::int('extras.insurance_bps'))),
            };
            if ($amount > 0) {
                $lines[] = ['key' => $k, 'label' => self::available()[$k]['label'], 'amount' => $amount];
            }
        }

        return $lines;
    }
}
