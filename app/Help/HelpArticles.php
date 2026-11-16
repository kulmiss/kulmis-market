<?php

namespace App\Help;

use App\Models\HelpArticle;
use App\Support\Features;
use App\Support\FeatureText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Illuminate\Support\Str;

/**
 * Member help articles. Defaults come from resources/help/*.md (front matter:
 * title, topic, order, feature, keywords); the Super Admin can change, hide or
 * add articles (help_articles). Numbers are written as {tokens} (HelpTokens),
 * and articles about switched-off features are hidden like the rest of the site.
 */
final class HelpArticles
{
    /** Topics in the order shown. */
    public const TOPICS = ['Getting started', 'How earning works', 'Inviting people', 'Your team', 'Wallet and cash-out',
        'Badges and bonuses', 'Signals', 'Shopping', 'Account safety'];

    /** @var array<string, array{slug:string, topic:string, title:string, body:string, feature:?string, position:int, keywords:string}>|null */
    private static ?array $defaults = null;

    /** @return array<string, array{slug:string, topic:string, title:string, body:string, feature:?string, position:int, keywords:string}> */
    public static function defaults(): array
    {
        if (self::$defaults !== null) {
            return self::$defaults;
        }
        $out = [];
        foreach (glob(resource_path('help/*.md')) ?: [] as $file) {
            $slug = basename($file, '.md');
            [$meta, $body] = self::frontMatter((string) file_get_contents($file));
            $out[$slug] = ['slug' => $slug, 'topic' => (string) ($meta['topic'] ?? 'Other'), 'title' => (string) ($meta['title'] ?? $slug),
                'body' => trim($body), 'feature' => isset($meta['feature']) && $meta['feature'] !== '' ? (string) $meta['feature'] : null,
                'position' => (int) ($meta['order'] ?? 50), 'keywords' => (string) ($meta['keywords'] ?? '')];
        }

        return self::$defaults = $out;
    }

    /**
     * Every article (defaults with the admin's changes, plus new ones), for the admin.
     *
     * @return list<array{slug:string, topic:string, title:string, body:string, feature:?string, position:int, keywords:string, is_published:bool, source:string, yes:int, no:int}>
     */
    public static function all(): array
    {
        return once(fn () => self::load()); // once per request (cleared when an article is saved)
    }

    /** @return list<array{slug:string, topic:string, title:string, body:string, feature:?string, position:int, keywords:string, is_published:bool, source:string, yes:int, no:int}> */
    private static function load(): array
    {
        $rows = HelpArticle::query()->get()->keyBy('slug');
        $votes = DB::table('help_feedback')->get()->keyBy('slug');
        $list = [];
        foreach (self::defaults() as $slug => $d) {
            $r = $rows[$slug] ?? null;
            $list[$slug] = ($r ? ['topic' => $r->topic, 'title' => $r->title, 'body' => $r->body, 'feature' => $r->feature, 'position' => $r->position] : $d)
                + ['slug' => $slug, 'keywords' => $d['keywords'], 'is_published' => $r ? $r->is_published : true, 'source' => $r ? 'edited' : 'default'];
        }
        foreach ($rows as $slug => $r) {
            if (! isset($list[$slug])) {
                $list[$slug] = ['slug' => $slug, 'topic' => $r->topic, 'title' => $r->title, 'body' => $r->body, 'feature' => $r->feature,
                    'position' => $r->position, 'keywords' => '', 'is_published' => $r->is_published, 'source' => 'new'];
            }
        }
        foreach ($list as $slug => $a) {
            $list[$slug] += ['yes' => (int) ($votes[$slug]->yes ?? 0), 'no' => (int) ($votes[$slug]->no ?? 0)];
        }
        $topicOrder = array_flip(self::TOPICS);
        uasort($list, fn ($a, $b) => [$topicOrder[$a['topic']] ?? 99, $a['position'], $a['title']] <=> [$topicOrder[$b['topic']] ?? 99, $b['position'], $b['title']]);

        return array_values($list);
    }

    /**
     * What members see: published, its switches on, and not about a switched-off feature.
     *
     * @return list<array{slug:string, topic:string, title:string, body:string, feature:?string, position:int, keywords:string, is_published:bool, source:string, yes:int, no:int}>
     */
    public static function visible(): array
    {
        $all = self::all();
        $key = Features::fingerprint().md5(serialize(array_map(fn ($a) => [$a['slug'], $a['title'], $a['body'], $a['feature'], $a['is_published']], $all)));
        if (! isset(self::$visible[$key])) {
            if (count(self::$visible) > 20) {
                self::$visible = [];
            }
            // Like the rest of the site: an article that still talks about a switched-off feature
            // (after its {if} parts and list lines are removed) is not shown at all.
            self::$visible[$key] = array_map(fn ($a) => $a['slug'], array_values(array_filter($all, fn ($a) => $a['is_published'] && self::switchesOn($a['feature'])
                && FeatureText::mentionsOff(HelpTokens::apply($a['title']).' '.self::plain($a['body'])) === [])));
        }
        $slugs = array_flip(self::$visible[$key]);

        return array_values(array_filter($all, fn ($a) => isset($slugs[$a['slug']])));
    }

    /** @var array<string, list<string>> visible slugs per switches + texts */
    private static array $visible = [];

    /** The topic's name as shown (no words about switched-off features). */
    public static function topicLabel(string $topic): string
    {
        return match ($topic) {
            'Badges and bonuses' => Features::enabled('badges') ? $topic : 'Bonuses and contests',
            'Wallet and cash-out' => Features::enabled('wallet.cashout') ? $topic : 'Your wallet',
            'Your team' => Features::enabled('referral') ? $topic : 'People you invited',
            default => $topic,
        };
    }

    /** Search words for an article, without words about switched-off features. */
    public static function keywords(string $keywords): string
    {
        return implode(', ', array_filter(array_map('trim', explode(',', $keywords)), fn ($k) => $k !== '' && FeatureText::mentionsOff($k) === []));
    }

    /** @return array{slug:string, topic:string, title:string, body:string, feature:?string, position:int, keywords:string, is_published:bool, source:string, yes:int, no:int}|null */
    public static function find(string $slug): ?array
    {
        foreach (self::visible() as $a) {
            if ($a['slug'] === $slug) {
                return $a;
            }
        }

        return null;
    }

    public static function exists(string $slug): bool
    {
        return self::find($slug) !== null;
    }

    /** Markdown → safe HTML, with live numbers and the parts about switched-off features removed. */
    public static function html(string $body): string
    {
        return Str::markdown(FeatureText::markdown(HelpTokens::apply($body)), ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /** Plain text (for search and the article list). */
    public static function plain(string $body): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags(self::html($body))));
    }

    /**
     * @param  list<array{slug:string, topic:string, title:string, body:string, keywords:string}>  $articles
     * @return list<array{slug:string, topic:string, title:string, body:string, keywords:string}>
     */
    public static function search(array $articles, string $q): array
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($q))) ?: []);
        if ($words === []) {
            return $articles;
        }

        return array_values(array_filter($articles, function ($a) use ($words) {
            $hay = mb_strtolower($a['title'].' '.$a['keywords'].' '.$a['topic'].' '.self::plain($a['body']));
            foreach ($words as $w) {
                if (! str_contains($hay, $w)) {
                    return false;
                }
            }

            return true;
        }));
    }

    public static function switchesOn(?string $features): bool
    {
        foreach (array_filter(array_map('trim', explode(',', (string) $features))) as $f) {
            if (! Features::exists($f) || Features::disabled($f)) {
                return false;
            }
        }

        return true;
    }

    public static function forget(): void
    {
        self::$defaults = null;
        Once::flush();
    }

    /** @return array{0: array<string, string>, 1: string} */
    private static function frontMatter(string $text): array
    {
        if (! preg_match('/^---\R(.*?)\R---\R(.*)$/s', $text, $m)) {
            return [[], $text];
        }
        $meta = [];
        foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $kv)) {
                $meta[$kv[1]] = trim($kv[2]);
            }
        }

        return [$meta, $m[2]];
    }
}
