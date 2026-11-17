<?php

namespace App\Home;

/**
 * Ready-made layouts. "current" is the fixed home page as it was before the
 * builder, section by section, so publishing it changes nothing a visitor sees.
 */
final class HomeTemplates
{
    /** @return array<string, array{label:string, description:string}> */
    public static function list(): array
    {
        return collect(self::definitions())->map(fn ($t) => ['label' => $t['label'], 'description' => $t['description']])->all();
    }

    /** @return list<array{id:string, type:string, on:bool, data:array<string, mixed>}> */
    public static function sections(string $key): array
    {
        $def = self::definitions()[$key] ?? throw new \InvalidArgumentException("Unknown template $key");

        return Sections::normalize(array_map(
            fn (array $s) => ['type' => $s[0], 'on' => true, 'data' => [...SectionTypes::get($s[0])['defaults'], ...($s[1] ?? [])]],
            $def['sections'],
        ));
    }

    /** @return array<string, array{label:string, description:string, sections:list<array{0:string, 1?:array<string, mixed>}>}> */
    private static function definitions(): array
    {
        return [
            'current' => [
                'label' => 'Current home page',
                'description' => 'Exactly the home page you have today. Start here and change what you like.',
                'sections' => [
                    ['hero'],
                    ['banners'],
                    ['categories'],
                    ['products', ['title' => 'New from the workshop', 'source' => 'newest', 'count' => 8, 'layout' => 'grid', 'see_all' => true]],
                    ['products', ['title' => 'Members-only product of the month', 'source' => 'members_only', 'count' => 1, 'layout' => 'spotlight', 'see_all' => false]],
                    ['membership'],
                ],
            ],
        ];
    }
}
