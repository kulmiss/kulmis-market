<?php

namespace App\Support;

/**
 * Texts the Super Admin can edit (messages, FAQ, pages, announcements, share
 * kit) can hold optional parts that disappear while a feature is off:
 *   "Keep your discounts{if signals}, trading signals{/if} and earnings."
 *   "{if !signals}Ask us about our next service.{/if}"
 * Blocks can be inside each other. An unknown switch name keeps its text.
 */
final class FeatureText
{
    private const BLOCK = '/\{if (!?)([a-z0-9_.]+)\}((?:(?!\{if ).)*?)\{\/if\}/s';

    public static function apply(string $text): string
    {
        if (! str_contains($text, '{if ')) {
            return $text;
        }
        for ($i = 0; $i < 10 && preg_match(self::BLOCK, $text); $i++) { // innermost blocks first
            $text = (string) preg_replace_callback(self::BLOCK, function (array $m) {
                if (! Features::exists($m[2])) {
                    return $m[3];
                }
                $on = Features::enabled($m[2]);

                return ($m[1] === '!' ? ! $on : $on) ? $m[3] : '';
            }, $text);
        }

        // Tidy what a removed part leaves behind: double spaces, a space before a comma or full stop.
        return (string) preg_replace(['/[ \t]{2,}/', '/ +([,.;:!?])/', '/\(\s*\)/'], [' ', '$1', ''], $text);
    }

    /**
     * A Markdown page with its {if} parts applied, and without the sections,
     * list items and table rows that talk about a switched-off feature (so the
     * terms page simply has no "Membership and signals" part while those are
     * off). Plain sentences are kept: the admin warns about them.
     */
    public static function markdown(string $md): string
    {
        $out = [];
        $skipBelow = null; // heading level of a dropped section
        foreach (preg_split('/\R/', self::apply($md)) ?: [] as $line) {
            if (preg_match('/^(#{1,6})\s/', $line, $h)) {
                $level = strlen($h[1]);
                if ($skipBelow !== null && $level > $skipBelow) {
                    continue;
                }
                $skipBelow = self::mentionsOff($line) !== [] ? $level : null;
                if ($skipBelow !== null) {
                    continue;
                }
            } elseif ($skipBelow !== null) {
                continue;
            } elseif (preg_match('/^\s*([-*+]|\d+\.|\|)\s?/', $line) && ! preg_match('/^\s*\|[\s|:-]+\|?\s*$/', $line) && self::mentionsOff($line) !== []) {
                continue;
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * Switched-off features this text still talks about (outside {if} blocks).
     *
     * @return list<string> feature labels, e.g. ["Signals"]
     */
    public static function mentionsOff(string $text): array
    {
        $plain = mb_strtolower(strip_tags((string) preg_replace(self::BLOCK, '', $text)));
        $found = [];
        foreach ((array) config('feature_words') as $feature => $words) {
            if (! Features::exists($feature) || Features::enabled($feature)) {
                continue;
            }
            foreach ($words as $w) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($w, '/').'(?![\p{L}\p{N}])/u', $plain)) {
                    $found[] = (string) (config('features')[$feature][0] ?? $feature);
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }
}
