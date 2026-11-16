<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/** The shop's logo and main colour (Settings → Appearance and the setup wizard). */
final class Branding
{
    public const DEFAULT_COLOR = '#18743f';

    /** Public URL of the uploaded logo, or null for the built-in mark. */
    public static function logoUrl(): ?string
    {
        $path = Settings::string('appearance.logo');

        return preg_match('#^branding/logo-[a-z0-9]{12}\.png$#', $path) && Storage::disk('public')->exists($path)
            ? Storage::disk('public')->url($path) : null;
    }

    /**
     * CSS variables for the shop's main colour (an inline style on <html>),
     * or '' when the default green is used. 600 = buttons, 700 = hover, 500 = focus rings.
     */
    public static function shopColorStyle(): string
    {
        $hex = Settings::string('appearance.shop_color');
        if (! preg_match('/^#[0-9a-f]{6}$/i', $hex) || strtolower($hex) === self::DEFAULT_COLOR) {
            return '';
        }

        return '--color-brand-500: '.self::mix($hex, '#ffffff', 0.15).'; --color-brand-600: '.strtolower($hex).'; --color-brand-700: '.self::mix($hex, '#000000', 0.2).';';
    }

    private static function mix(string $a, string $b, float $t): string
    {
        $ca = sscanf($a, '#%02x%02x%02x');
        $cb = sscanf($b, '#%02x%02x%02x');
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= sprintf('%02x', (int) round($ca[$i] + ($cb[$i] - $ca[$i]) * $t));
        }

        return $out;
    }
}
