<?php

namespace App\Home;

use App\Models\HomeMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Cleans what the editor sends (unknown sections and fields are dropped,
 * texts are cut to their length, numbers kept in range) and lists what must
 * be fixed before publishing (bad links, pictures without a description…).
 *
 * A draft is saved even with problems (so nothing typed is lost); publishing
 * is refused until the errors are fixed. Links are checked again when the
 * page is shown, so a bad link can never reach a visitor.
 */
final class Sections
{
    public const MAX_SECTIONS = 60;

    /**
     * @return list<array{id:string, type:string, on:bool, data:array<string, mixed>}>
     */
    public static function normalize(mixed $raw): array
    {
        $out = [];
        $seen = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $s) {
            if (! is_array($s) || ! is_string($s['type'] ?? null) || ! ($def = SectionTypes::get($s['type']))) {
                continue;
            }
            $id = is_string($s['id'] ?? null) && preg_match('/^[a-z0-9]{4,24}$/', $s['id']) && ! isset($seen[$s['id']]) ? $s['id'] : self::newId();
            $seen[$id] = true;
            $out[] = [
                'id' => $id, 'type' => $s['type'], 'on' => (bool) ($s['on'] ?? true),
                'data' => self::fields($def['fields'], is_array($s['data'] ?? null) ? $s['data'] : [], $def['defaults']),
            ];
            if (count($out) >= self::MAX_SECTIONS) {
                break;
            }
        }

        return $out;
    }

    /**
     * A new section of this type with its starting values.
     *
     * @return array{id:string, type:string, on:bool, data:array<string, mixed>}
     */
    public static function make(string $type): array
    {
        $def = SectionTypes::get($type) ?? throw new \InvalidArgumentException("Unknown section $type");

        return ['id' => self::newId(), 'type' => $type, 'on' => true, 'data' => self::fields($def['fields'], $def['defaults'], $def['defaults'])];
    }

    public static function newId(): string
    {
        return Str::lower(Str::random(10));
    }

    /** @return array{title:string, description:string, image:?int} */
    public static function seo(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        return [
            'title' => self::text($raw['title'] ?? '', 70),
            'description' => self::text($raw['description'] ?? '', 200),
            'image' => self::id($raw['image'] ?? null),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private static function fields(array $fields, array $data, array $defaults): array
    {
        $out = [];
        foreach ($fields as $key => $f) {
            $has = array_key_exists($key, $data);
            $v = $has ? $data[$key] : ($defaults[$key] ?? null);
            $out[$key] = match ($f['type']) {
                'text' => self::text($v, $f['max'] ?? 160),
                'textarea' => self::text($v, $f['max'] ?? 600, true),
                'link' => self::text($v, 500),
                'select' => is_string($v) && isset($f['options'][$v]) ? $v : ($defaults[$key] ?? array_key_first($f['options'])),
                'toggle' => (bool) $v,
                'number' => max($f['min'] ?? 0, min($f['max'] ?? 100, is_numeric($v) ? (int) $v : (int) ($defaults[$key] ?? $f['min'] ?? 0))),
                'image', 'video' => self::id($v),
                'color' => self::color($v, (string) ($defaults[$key] ?? 'none')),
                'checks' => array_values(array_intersect(array_keys($f['options']), is_array($v) ? array_filter($v, 'is_string') : [])),
                'categories', 'products', 'faqs', 'testimonials' => self::ids($v, $f['max'] ?? 24),
                'list' => array_map(
                    fn ($item) => self::fields($f['fields'], is_array($item) ? $item : [], []),
                    array_slice(is_array($v) ? array_values($v) : [], 0, $f['max'] ?? 12),
                ),
                default => null,
            };
        }

        return $out;
    }

    private static function text(mixed $v, int $max, bool $lines = false): string
    {
        if (! is_scalar($v)) {
            return '';
        }
        $s = (string) $v;
        $s = preg_replace($lines ? '/[^\P{C}\n]/u' : '/\p{C}/u', $lines ? '' : ' ', $s) ?? '';
        if ($lines) {
            $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? '';
        }

        return mb_substr(trim($s), 0, $max);
    }

    private static function id(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    /** @return list<int> */
    private static function ids(mixed $v, int $max): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($x) => self::id($x), is_array($v) ? $v : []))));

        return array_slice($ids, 0, $max);
    }

    private static function color(mixed $v, string $default): string
    {
        if (is_string($v) && (isset(SectionTypes::ACCENTS[$v]) || preg_match('/^#[0-9a-f]{6}$/', strtolower($v)))) {
            return strtolower($v);
        }

        return $default;
    }

    /**
     * What to fix before publishing (errors) and what to look at (warnings).
     *
     * @param  list<array{id:string, type:string, on:bool, data:array<string, mixed>}>  $sections
     * @param  array{title:string, description:string, image:?int}  $seo
     * @return array{errors:list<array{section:?string, field:?string, message:string}>, warnings:list<array{section:?string, field:?string, message:string}>}
     */
    public static function problems(array $sections, array $seo): array
    {
        $errors = $warnings = [];
        $media = HomeMedia::query()->whereIn('id', self::mediaIds($sections, $seo))->get()->keyBy('id');
        $label = fn (array $s) => SectionTypes::get($s['type'])['label'] ?? $s['type'];

        $visible = array_values(array_filter($sections, fn ($s) => $s['on']));
        if ($visible === []) {
            $errors[] = ['section' => null, 'field' => null, 'message' => 'The page is empty. Switch on or add at least one section.'];
        }
        $firstHero = collect($visible)->search(fn ($s) => $s['type'] === 'hero');
        if ($visible !== [] && $firstHero === false) {
            $warnings[] = ['section' => null, 'field' => null, 'message' => 'There is no Hero. Add one at the top: it gives the page its main heading (good for Google and screen readers).'];
        } elseif ($firstHero !== false && collect($visible)->take($firstHero)->contains(fn ($s) => ! in_array($s['type'], ['announcement', 'banners', 'spacer'], true))) {
            $warnings[] = ['section' => $visible[$firstHero]['id'], 'field' => null, 'message' => 'Put the Hero first (an announcement bar above it is fine), so the main heading comes first.'];
        }

        foreach ($sections as $s) {
            $def = SectionTypes::get($s['type']);
            if (! $def) {
                continue;
            }
            $where = $label($s);
            self::check($def['fields'], $s['data'], $s['id'], $where, $media, $errors, $warnings, $s['on']);
            if ($s['on'] && ($off = SectionTypes::offFeatures($s['type'], $s['data'])) !== []) {
                $warnings[] = ['section' => $s['id'], 'field' => null, 'message' => "$where is hidden now: ".self::switchNames($off).' is off.'];
            }
        }

        if ($seo['image'] !== null && ! isset($media[$seo['image']])) {
            $errors[] = ['section' => 'seo', 'field' => 'image', 'message' => 'The social picture was deleted. Pick another one.'];
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     * @param  Collection<int, HomeMedia>  $media
     * @param  list<array{section:?string, field:?string, message:string}>  $errors
     * @param  list<array{section:?string, field:?string, message:string}>  $warnings
     */
    private static function check(array $fields, array $data, string $id, string $where, $media, array &$errors, array &$warnings, bool $on, string $prefix = ''): void
    {
        foreach ($fields as $key => $f) {
            $v = $data[$key] ?? null;
            $path = $prefix.$key;
            $name = $where.' → '.$f['label'];
            if (isset($f['when']) && ($data[$f['when'][0]] ?? null) !== $f['when'][1]) {
                continue;
            }
            if (! $on) {
                // A switched-off section is not shown: only links are checked (so it is safe to switch on later).
                if ($f['type'] === 'link' && $v !== '' && SafeLink::clean((string) $v) === null) {
                    $errors[] = ['section' => $id, 'field' => $path, 'message' => "$name is not an allowed link. ".SafeLink::HELP.'.'];
                }

                continue;
            }
            $add = function (string $message) use (&$errors, $id, $path, $name) {
                $errors[] = ['section' => $id, 'field' => $path, 'message' => "$name $message"];
            };
            if (in_array($f['type'], ['text', 'textarea'], true) && ! empty($f['required']) && $v === '') {
                $add('is empty.');
            } elseif ($f['type'] === 'link' && $v !== '' && SafeLink::clean((string) $v) === null) {
                $add('is not an allowed link. '.SafeLink::HELP.'.');
            } elseif (in_array($f['type'], ['image', 'video'], true) && $v !== null) {
                if (! isset($media[$v])) {
                    $add('was deleted. Pick another one.');
                } elseif ($media[$v]->kind === 'image' && trim((string) $media[$v]->alt) === '') {
                    $add('needs a short description (alt text) for people who cannot see it.');
                }
            } elseif ($f['type'] === 'color' && str_starts_with((string) $v, '#') && Contrast::best((string) $v)[1] < 4.5) {
                $warnings[] = ['section' => $id, 'field' => $path, 'message' => "$name: text on this colour is hard to read. Pick a darker or lighter colour."];
            } elseif ($f['type'] === 'list') {
                foreach (array_values((array) $v) as $i => $item) {
                    self::check($f['fields'], (array) $item, $id, $where.' #'.($i + 1), $media, $errors, $warnings, true, "$path.$i.");
                }
            }
            // Button text and link go together.
            if ($f['type'] === 'link' && preg_match('/^(.*)_link$/', $key, $m) && isset($fields[$m[1].'_text'])) {
                $text = (string) ($data[$m[1].'_text'] ?? '');
                if (($text === '') !== ($v === '')) {
                    $warnings[] = ['section' => $id, 'field' => $path, 'message' => "$where: ".($text === '' ? 'a link without button text is not shown.' : 'a button without a link is not shown.')];
                }
            }
        }
    }

    /**
     * @param  list<array{id:string, type:string, on:bool, data:array<string, mixed>}>  $sections
     * @param  array{image:?int}|array<string, mixed>  $seo
     * @return list<int>
     */
    public static function mediaIds(array $sections, array $seo = []): array
    {
        $ids = [];
        $walk = function (array $fields, array $data) use (&$walk, &$ids) {
            foreach ($fields as $key => $f) {
                if (in_array($f['type'], ['image', 'video'], true) && is_int($data[$key] ?? null)) {
                    $ids[] = $data[$key];
                } elseif ($f['type'] === 'list') {
                    foreach ((array) ($data[$key] ?? []) as $item) {
                        $walk($f['fields'], (array) $item);
                    }
                }
            }
        };
        foreach ($sections as $s) {
            if ($def = SectionTypes::get($s['type'])) {
                $walk($def['fields'], $s['data']);
            }
        }
        if (is_int($seo['image'] ?? null)) {
            $ids[] = $seo['image'];
        }

        return array_values(array_unique($ids));
    }

    /** @param list<string> $features */
    public static function switchNames(array $features): string
    {
        return collect($features)->map(fn ($f) => '"'.(config('features')[$f][0] ?? $f).'"')->implode(' and ');
    }
}
