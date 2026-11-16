<?php

namespace App\Help;

use App\Support\Money;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * The Super Admin guide: docs/admin-guide/*.md (front matter: title, part,
 * order, pages = the admin screens it explains). Shown in the admin (Guide,
 * and the "?" on every page) and exported as docs/admin-guide.pdf. Numbers
 * like {membership_price} and the {money_rules} table come from the settings.
 */
final class AdminGuide
{
    /** Settings groups shown in the money rules table. */
    public const MONEY_GROUPS = ['Membership', 'Signals', 'Pricing', 'Referral', 'Wallet', 'Badge bonus', 'Contests and challenges', 'Engagement'];

    /** @return list<array{slug:string, title:string, part:string, order:int, pages:list<string>, body:string}> */
    public static function pages(): array
    {
        return once(function () {
            $out = [];
            foreach (glob(base_path('docs/admin-guide/*.md')) ?: [] as $file) {
                $slug = basename($file, '.md');
                if ($slug === 'README') {
                    continue;
                }
                $text = (string) file_get_contents($file);
                $meta = [];
                if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', $text, $m)) {
                    foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
                        if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $kv)) {
                            $meta[$kv[1]] = trim($kv[2]);
                        }
                    }
                    $text = $m[2];
                }
                $out[] = ['slug' => $slug, 'title' => $meta['title'] ?? $slug, 'part' => $meta['part'] ?? 'Other', 'order' => (int) ($meta['order'] ?? 50),
                    'pages' => array_values(array_filter(array_map('trim', explode(',', $meta['pages'] ?? '')))), 'body' => trim($text)];
            }
            usort($out, fn ($a, $b) => [$a['part'], $a['order']] <=> [$b['part'], $b['order']]);

            return $out;
        });
    }

    /** @return array{slug:string, title:string, part:string, order:int, pages:list<string>, body:string}|null */
    public static function find(string $slug): ?array
    {
        foreach (self::pages() as $p) {
            if ($p['slug'] === $slug) {
                return $p;
            }
        }

        return null;
    }

    /** The guide page for an admin screen (longest matching path), e.g. /cash-outs/12 → daily-wallets-cash-outs. */
    public static function forScreen(string $path): ?string
    {
        $path = '/'.trim($path, '/');
        $best = null;
        $len = -1;
        foreach (self::pages() as $p) {
            foreach ($p['pages'] as $screen) {
                $match = $screen === '/' ? $path === '/' : ($path === $screen || str_starts_with($path, rtrim($screen, '/').'/'));
                if ($match && strlen($screen) > $len) {
                    [$best, $len] = [$p['slug'], strlen($screen)];
                }
            }
        }

        return $best;
    }

    /**
     * The page as Markdown with live numbers. $for: 'admin' links to /admin/guide/…, 'pdf' to anchors in the one PDF.
     */
    public static function markdown(string $body, string $for = 'admin'): string
    {
        $md = str_replace('{money_rules}', self::moneyRules(), HelpTokens::apply($body));
        // Links to other guide pages, and pictures.
        $md = (string) preg_replace_callback('/\]\(([a-z0-9-]+)\.md(#[a-z0-9-]+)?\)/', fn ($m) => $for === 'pdf' ? "](#{$m[1]})" : "](/admin/guide/{$m[1]})", $md);

        return (string) preg_replace_callback('#\]\(\.\./screenshots/([a-z0-9-]+\.(?:jpg|png))\)#', fn ($m) => $for === 'pdf'
            ? ']('.base_path('docs/screenshots/'.$m[1]).')' : '](/api/v1/admin/guide/images/'.$m[1].')', $md);
    }

    public static function html(string $body, string $for = 'admin'): string
    {
        $html = Str::markdown(self::markdown($body, $for), ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        // Task-list boxes "[ ]" become real (read-only) check boxes.
        return str_replace(['<li>[ ] ', '<li>[x] '], ['<li class="task"><input type="checkbox" disabled> ', '<li class="task"><input type="checkbox" checked disabled> '], $html);
    }

    /** All money settings as a Markdown table, read live. */
    public static function moneyRules(): string
    {
        $rows = ['| Group | Rule | Now |', '|---|---|---|'];
        foreach (Settings::all() as $s) {
            if (! in_array($s['group'], self::MONEY_GROUPS, true) || ! in_array($s['type'], ['money', 'bps', 'int', 'ratio'], true)) {
                continue;
            }
            $value = match ($s['type']) {
                'money' => Money::format((int) $s['value']),
                'bps' => rtrim(rtrim(number_format(((int) $s['value']) / 100, 2, '.', ''), '0'), '.').'%',
                'ratio' => (((int) $s['value']) / 100).'×',
                default => (string) $s['value'],
            };
            $rows[] = '| '.$s['group'].' | '.str_replace('|', '/', $s['label']).' | **'.$value.'** |';
        }

        return implode("\n", $rows);
    }

    /** The whole guide as one PDF (live numbers). */
    public static function pdf(): string
    {
        return Pdf::loadView('pdf.admin-guide', ['pages' => self::pages(), 'site' => Settings::string('site.name')])
            ->setPaper('a4')->output();
    }

    public static function plain(string $body): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags(self::html($body))));
    }
}
