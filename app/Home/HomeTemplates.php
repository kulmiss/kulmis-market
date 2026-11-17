<?php

namespace App\Home;

use App\Models\User;

/**
 * Ready-made layouts. "current" is the fixed home page as it was before the
 * builder, section by section, so publishing it changes nothing a visitor sees.
 */
final class HomeTemplates
{
    /** @return array<string, array{label:string, description:string}> */
    public static function list(): array
    {
        return collect(self::definitions(['images' => [], 'quotes' => []]))->map(fn ($t) => ['label' => $t['label'], 'description' => $t['description']])->all();
    }

    /** @return list<array{id:string, type:string, on:bool, data:array<string, mixed>}> */
    public static function sections(string $key, ?User $by = null): array
    {
        $demo = $key === 'investor_demo' ? app(HomeDemo::class)->ensure($by) : ['images' => [], 'quotes' => []];
        $def = self::definitions($demo)[$key] ?? throw new \InvalidArgumentException("Unknown template $key");

        return Sections::normalize(array_map(
            fn (array $s) => ['type' => $s[0], 'on' => $s[2] ?? true, 'data' => [...SectionTypes::get($s[0])['defaults'], ...($s[1] ?? [])]],
            $def['sections'],
        ));
    }

    /**
     * @param  array{images:list<int>, quotes:list<int>}  $demo
     * @return array<string, array{label:string, description:string, sections:list<array{0:string, 1?:array<string, mixed>, 2?:bool}>}>
     */
    private static function definitions(array $demo): array
    {
        $img = fn (int $i) => $demo['images'][$i] ?? null;

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
            'shop_first' => [
                'label' => 'Shop first',
                'description' => 'Products right at the top: categories, newest pieces, flash sale and best sellers.',
                'sections' => [
                    ['announcement', ['text' => 'New pieces every week. Delivered all over Ethiopia.', 'link_text' => 'Shop now', 'link' => '/shop', 'accent' => 'dark']],
                    ['hero', ['label' => 'New this week', 'headline' => 'Handmade pieces, ready to send.', 'button2_text' => '', 'button2_link' => '']],
                    ['categories', ['style' => 'cards', 'title' => 'Shop by category']],
                    ['products', ['title' => 'New from the workshop', 'source' => 'newest', 'count' => 8]],
                    ['flash_sale'],
                    ['products', ['title' => 'Best sellers', 'source' => 'best_sellers', 'count' => 8, 'layout' => 'carousel']],
                    ['how_it_works'],
                    ['membership', ['title' => 'Save more as a member']],
                    ['contact'],
                ],
            ],
            'membership_first' => [
                'label' => 'Membership first',
                'description' => 'Starts with what members get, with today\'s numbers, then the shop.',
                'sections' => [
                    ['hero', ['label' => 'Membership', 'headline' => 'Save {member_discount} on every handmade piece.',
                        'subline' => 'One membership: {membership_price} a month. Member prices in the shop and more.',
                        'button1_text' => 'Become a member', 'button1_link' => '/membership', 'button2_text' => 'See the shop', 'button2_link' => '/shop']],
                    ['membership', ['title' => 'What members get']],
                    ['products', ['title' => 'Members-only product of the month', 'source' => 'members_only', 'count' => 1, 'layout' => 'spotlight', 'see_all' => false]],
                    ['how_you_earn'],
                    ['signals'],
                    ['faq'],
                    ['cta', ['title' => 'Join today', 'text' => 'Member prices start right away.', 'button1_text' => 'Become a member', 'button1_link' => '/membership']],
                ],
            ],
            'story_first' => [
                'label' => 'Story first',
                'description' => 'Who you are and who makes the pieces, before the products.',
                'sections' => [
                    ['hero', ['label' => 'Our story', 'layout' => 'behind', 'align' => 'center', 'button2_text' => '', 'button2_link' => '']],
                    ['stories'],
                    ['image_text', ['image_side' => 'right']],
                    ['products', ['title' => 'Made by them', 'source' => 'newest', 'count' => 4]],
                    ['testimonials'],
                    ['numbers'],
                    ['cta'],
                    ['contact'],
                ],
            ],
            'investor_demo' => [
                'label' => 'Investor demo',
                'description' => 'Every kind of section, filled with demo pictures and quotes (marked "Demo"). Needs "Show demo data" on.',
                'sections' => [
                    ['announcement', ['text' => 'Demo page: the pictures and quotes are sample data.', 'link_text' => '', 'link' => '', 'accent' => 'gold']],
                    ['hero'],
                    ['banners'],
                    ['categories', ['title' => 'Shop by category', 'style' => 'cards']],
                    ['products', ['title' => 'New from the workshop', 'source' => 'newest', 'count' => 8]],
                    ['flash_sale'],
                    ['products', ['title' => 'Featured', 'source' => 'featured', 'count' => 8, 'layout' => 'carousel']],
                    ['products', ['title' => 'Members-only product of the month', 'source' => 'members_only', 'count' => 1, 'layout' => 'spotlight', 'see_all' => false]],
                    ['membership', ['title' => 'Membership']],
                    ['signals'],
                    ['how_it_works', ['steps' => [
                        ['icon' => 'bag', 'title' => 'Pick something you love', 'text' => 'Every piece is made by hand by makers we know.'],
                        ['icon' => 'phone', 'title' => 'Pay by bank or Telebirr', 'text' => 'Send the transfer and a photo of the receipt.'],
                        ['icon' => 'hand', 'title' => 'We make or pack it', 'text' => 'Ready pieces leave in 1–2 days.'],
                        ['icon' => 'truck', 'title' => 'Delivered to you', 'text' => 'All over Ethiopia. Track it any time.'],
                    ]]],
                    ['how_you_earn'],
                    ['stories', ['items' => [
                        ['image' => $img(2), 'name' => 'Leather workshop', 'place' => 'Addis Ababa', 'text' => 'Demo story: bags and belts cut and stitched by hand.', 'link_text' => '', 'link' => ''],
                        ['image' => $img(3), 'name' => 'Weavers', 'place' => 'Gondar', 'text' => 'Demo story: cotton scarves woven on wooden looms.', 'link_text' => '', 'link' => ''],
                        ['image' => $img(4), 'name' => 'Potters', 'place' => 'Sululta', 'text' => 'Demo story: jebena pots shaped and fired in a wood kiln.', 'link_text' => '', 'link' => ''],
                    ]]],
                    ['image_text', ['title' => 'Coffee, the slow way', 'text' => 'Demo text: a picture next to a few lines about what makes your shop special.', 'image' => $img(0), 'accent' => 'light',
                        'button1_text' => 'See the coffee pots', 'button1_link' => '/shop']],
                    ['testimonials', ['pick' => $demo['quotes']]],
                    ['numbers', ['title' => 'In numbers', 'stats' => ['products', 'customers', 'orders', 'cities', 'members', 'signal_win_rate']]],
                    ['faq'],
                    // Off until you paste your own YouTube or Vimeo link (we have no demo video to show).
                    ['video', ['title' => 'A video', 'source' => 'link', 'url' => '', 'poster' => $img(1), 'caption' => 'Paste your YouTube or Vimeo link, then switch this section on.'], false],
                    ['gallery', ['items' => array_map(fn ($id) => ['image' => $id, 'caption' => ''], $demo['images'])]],
                    ['cta'],
                    ['contact'],
                    ['spacer', ['line' => true]],
                ],
            ],
        ];
    }
}
