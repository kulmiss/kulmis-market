<?php

namespace App\Home;

use App\Support\Features;

/**
 * Every kind of section the home page builder can add, with the fields the
 * side panel shows, the starting values and the switches it needs.
 *
 * Field types: text, textarea, link, select, toggle, number, image, video,
 * color, checks, list (repeating items), categories, products, faqs,
 * testimonials (picked rows, in order).
 *
 * Texts can use live numbers like {membership_price} or {member_discount}
 * (the same words as in help articles), so they are right after a setting changes.
 */
final class SectionTypes
{
    /** Background colours. Each pair passes the 4.5 : 1 contrast rule. */
    public const ACCENTS = [
        'soft' => 'bg-gradient-to-b from-mist-100 to-mist-50 dark:from-ink-800 dark:to-ink-900',
        'green' => 'bg-brand-700 text-white dark:bg-ink-800',
        'gold' => 'bg-gold-500 text-white',
        'dark' => 'bg-ink-900 text-mist-100',
        'light' => 'bg-mist-100 text-ink-900 dark:bg-ink-800 dark:text-mist-100',
        'none' => '',
    ];

    public const ACCENT_LABELS = ['soft' => 'Soft green', 'green' => 'Green', 'gold' => 'Gold', 'dark' => 'Dark', 'light' => 'Light', 'none' => 'No colour'];

    /** Small line icons for "How it works" (SVG paths, drawn by us). */
    public const ICONS = [
        'bag' => 'M6 7h12l-1 13H7L6 7zm3 0a3 3 0 0 1 6 0',
        'star' => 'M12 3l2.6 5.6 6 .7-4.5 4.1 1.2 6L12 16.5 6.7 19.4l1.2-6L3.4 9.3l6-.7z',
        'truck' => 'M3 7h11v9H3zm11 3h4l3 3v3h-7M7 19a2 2 0 1 0 0-.01M17 19a2 2 0 1 0 0-.01',
        'heart' => 'M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z',
        'hand' => 'M8 13V5a1.5 1.5 0 0 1 3 0v6m0-1V4a1.5 1.5 0 0 1 3 0v6m0-1V6a1.5 1.5 0 0 1 3 0v8a6 6 0 0 1-6 6h-1a6 6 0 0 1-5-3l-2-4a1.5 1.5 0 0 1 2.5-1.5L8 13',
        'chat' => 'M4 5h16v11H8l-4 4z',
        'check' => 'M5 12l4 4 10-10',
        'gift' => 'M4 10h16v10H4zm-1-3h18v3H3zm9 0v13M12 7S10 3 8 4s0 3 4 3m0 0s2-4 4-3 0 3-4 3',
        'user' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 9a7 7 0 0 1 14 0',
        'phone' => 'M7 3h10v18H7zm4 15h2',
    ];

    /** @return array<string, array{label:string, group:string, icon:string, description:string, needs:list<string>, note?:string, fields:array<string, array<string, mixed>>, defaults:array<string, mixed>}> */
    public static function all(): array
    {
        $button = fn (int $n) => [
            "button{$n}_text" => ['type' => 'text', 'label' => "Button $n text", 'max' => 60],
            "button{$n}_link" => ['type' => 'link', 'label' => "Button $n link"],
        ];
        $accent = ['type' => 'color', 'label' => 'Background colour'];
        $title = ['type' => 'text', 'label' => 'Title', 'max' => 120];

        return [
            'hero' => [
                'label' => 'Hero', 'group' => 'Top of the page', 'icon' => 'star', 'needs' => [],
                'description' => 'The big first part: headline, short line, 1–2 buttons and a picture.',
                'fields' => [
                    'label' => ['type' => 'text', 'label' => 'Small label above', 'max' => 60],
                    'headline' => ['type' => 'text', 'label' => 'Headline', 'max' => 120, 'required' => true],
                    'subline' => ['type' => 'textarea', 'label' => 'Short line', 'max' => 300],
                    ...$button(1), ...$button(2),
                    'image_source' => ['type' => 'select', 'label' => 'Picture', 'options' => ['featured' => 'First featured product', 'image' => 'My picture', 'none' => 'No picture']],
                    'image' => ['type' => 'image', 'label' => 'My picture', 'when' => ['image_source', 'image']],
                    'layout' => ['type' => 'select', 'label' => 'Picture place', 'options' => ['right' => 'Right', 'left' => 'Left', 'behind' => 'Behind the text']],
                    'align' => ['type' => 'select', 'label' => 'Text alignment', 'options' => ['left' => 'Left', 'center' => 'Centre']],
                    'accent' => $accent,
                ],
                'defaults' => [
                    'label' => 'Handmade in Ethiopia', 'headline' => 'Beautiful things, made slowly by hand.',
                    'subline' => 'Woven, carved and stitched by makers we know by name. Shipped all over Ethiopia.',
                    'button1_text' => 'Shop the collection', 'button1_link' => '/shop',
                    'button2_text' => 'Become a member — save {member_discount}', 'button2_link' => '/membership',
                    'image_source' => 'featured', 'image' => null, 'layout' => 'right', 'align' => 'left', 'accent' => 'soft',
                ],
            ],
            'announcement' => [
                'label' => 'Announcement bar', 'group' => 'Top of the page', 'icon' => 'chat', 'needs' => [],
                'description' => 'One short line with a link, in a coloured band.',
                'fields' => [
                    'text' => ['type' => 'text', 'label' => 'Text', 'max' => 160, 'required' => true],
                    'link_text' => ['type' => 'text', 'label' => 'Link text', 'max' => 40],
                    'link' => ['type' => 'link', 'label' => 'Link'],
                    'accent' => $accent,
                ],
                'defaults' => ['text' => 'Free delivery for members over {free_delivery_over}', 'link_text' => 'See how', 'link' => '/membership', 'accent' => 'dark'],
            ],
            'banners' => [
                'label' => 'Home banners', 'group' => 'Top of the page', 'icon' => 'chat', 'needs' => [],
                'description' => 'The banners you add in Pages & banners for the home page, with their dates.',
                'note' => 'You edit these banners in Pages & banners.',
                'fields' => [],
                'defaults' => [],
            ],
            'categories' => [
                'label' => 'Category buttons', 'group' => 'Shop', 'icon' => 'bag', 'needs' => ['shop'],
                'description' => 'Buttons to your categories. Pick which ones and their order, or show all.',
                'fields' => [
                    'title' => $title,
                    'pick' => ['type' => 'categories', 'label' => 'Categories (empty = all, in your order)', 'max' => 24],
                    'limit' => ['type' => 'number', 'label' => 'How many at most', 'min' => 1, 'max' => 24],
                    'style' => ['type' => 'select', 'label' => 'Style', 'options' => ['chips' => 'Small buttons', 'cards' => 'Cards']],
                ],
                'defaults' => ['title' => '', 'pick' => [], 'limit' => 8, 'style' => 'chips'],
            ],
            'products' => [
                'label' => 'Product row', 'group' => 'Shop', 'icon' => 'bag', 'needs' => ['shop'],
                'description' => 'Newest, featured, best sellers, on sale, members-only or products you pick.',
                'fields' => [
                    'title' => $title,
                    'source' => ['type' => 'select', 'label' => 'Which products', 'options' => [
                        'newest' => 'Newest', 'featured' => 'Featured', 'best_sellers' => 'Best sellers (last 90 days)',
                        'on_sale' => 'On flash sale now', 'members_only' => 'Members-only', 'picked' => 'I pick them',
                    ]],
                    'picked' => ['type' => 'products', 'label' => 'Products', 'max' => 24, 'when' => ['source', 'picked']],
                    'count' => ['type' => 'number', 'label' => 'How many', 'min' => 1, 'max' => 24],
                    'layout' => ['type' => 'select', 'label' => 'Layout', 'options' => ['grid' => 'Grid', 'carousel' => 'Row you can swipe', 'spotlight' => 'One big product']],
                    'see_all' => ['type' => 'toggle', 'label' => 'Show "See all →"'],
                ],
                'defaults' => ['title' => 'New from the workshop', 'source' => 'newest', 'picked' => [], 'count' => 8, 'layout' => 'grid', 'see_all' => true],
            ],
            'flash_sale' => [
                'label' => 'Flash sale', 'group' => 'Shop', 'icon' => 'star', 'needs' => ['shop', 'shop.flash_sales'],
                'description' => 'Products on flash sale with a countdown.',
                'note' => 'Shows only while a flash sale is running.',
                'fields' => [
                    'title' => $title,
                    'text' => ['type' => 'text', 'label' => 'Short line', 'max' => 200],
                    'count' => ['type' => 'number', 'label' => 'How many products', 'min' => 1, 'max' => 12],
                ],
                'defaults' => ['title' => 'Flash sale', 'text' => 'Lower prices for a short time.', 'count' => 4],
            ],
            'membership' => [
                'label' => 'Membership', 'group' => 'Membership', 'icon' => 'heart', 'needs' => ['membership'],
                'description' => 'The member benefits with today\'s numbers from Settings, and a button.',
                'fields' => [
                    'title' => $title,
                    'text' => ['type' => 'textarea', 'label' => 'Short text', 'max' => 400],
                    'show_benefits' => ['type' => 'toggle', 'label' => 'Show the benefit cards'],
                    'button_text' => ['type' => 'text', 'label' => 'Button text', 'max' => 80],
                ],
                'defaults' => ['title' => '', 'text' => '', 'show_benefits' => true, 'button_text' => 'See membership — {membership_price}/month'],
            ],
            'signals' => [
                'label' => 'Signals', 'group' => 'Membership', 'icon' => 'chat', 'needs' => ['signals'],
                'description' => 'A short pitch for trading signals with a button.',
                'fields' => [
                    'title' => $title,
                    'text' => ['type' => 'textarea', 'label' => 'Short text', 'max' => 400],
                    ...$button(1),
                    'accent' => $accent,
                ],
                'defaults' => ['title' => 'Trading signals', 'text' => 'Clear trade ideas in Telegram, with the results of past signals for everyone to see. Included for members.',
                    'button1_text' => 'See signals', 'button1_link' => '/signals', 'accent' => 'light'],
            ],
            'how_it_works' => [
                'label' => 'How it works', 'group' => 'Explain', 'icon' => 'check', 'needs' => [],
                'description' => '3 or 4 easy steps with icons.',
                'fields' => [
                    'title' => $title,
                    'steps' => ['type' => 'list', 'label' => 'Steps', 'max' => 4, 'fields' => [
                        'icon' => ['type' => 'select', 'label' => 'Icon', 'options' => array_combine(array_keys(self::ICONS), array_map('ucfirst', array_keys(self::ICONS)))],
                        'title' => ['type' => 'text', 'label' => 'Step', 'max' => 80],
                        'text' => ['type' => 'textarea', 'label' => 'Text', 'max' => 240],
                    ]],
                ],
                'defaults' => ['title' => 'How it works', 'steps' => [
                    ['icon' => 'bag', 'title' => 'Pick something you love', 'text' => 'Every piece is made by hand by makers we know.'],
                    ['icon' => 'phone', 'title' => 'Pay by bank or Telebirr', 'text' => 'Send the transfer and a photo of the receipt.'],
                    ['icon' => 'truck', 'title' => 'We send it to you', 'text' => 'Delivered all over Ethiopia. Track it any time.'],
                ]],
            ],
            'how_you_earn' => [
                'label' => 'How you earn', 'group' => 'Membership', 'icon' => 'gift', 'needs' => ['membership', 'referral', 'referral.level1', 'referral.commission.membership'],
                'description' => 'A worked example in ETB from today\'s settings. It never promises income.',
                'fields' => [
                    'title' => $title,
                    'text' => ['type' => 'textarea', 'label' => 'Short text', 'max' => 400],
                    ...$button(1),
                ],
                'defaults' => ['title' => 'How you earn', 'text' => 'Members get a personal link. When people join with it, you earn a share of their membership.',
                    'button1_text' => 'How the program works', 'button1_link' => '/referral-program'],
            ],
            'stories' => [
                'label' => 'Maker stories / About us', 'group' => 'Story', 'icon' => 'user', 'needs' => [],
                'description' => 'One or more short stories, each with a picture.',
                'fields' => [
                    'title' => $title,
                    'items' => ['type' => 'list', 'label' => 'Stories', 'max' => 6, 'fields' => [
                        'image' => ['type' => 'image', 'label' => 'Picture'],
                        'name' => ['type' => 'text', 'label' => 'Name', 'max' => 80],
                        'place' => ['type' => 'text', 'label' => 'Town or craft', 'max' => 80],
                        'text' => ['type' => 'textarea', 'label' => 'Story', 'max' => 800],
                        'link_text' => ['type' => 'text', 'label' => 'Link text', 'max' => 40],
                        'link' => ['type' => 'link', 'label' => 'Link'],
                    ]],
                ],
                'defaults' => ['title' => 'Our makers', 'items' => [
                    ['image' => null, 'name' => 'Our workshop', 'place' => 'Addis Ababa', 'text' => 'Write who you are and how your pieces are made.', 'link_text' => '', 'link' => ''],
                ]],
            ],
            'image_text' => [
                'label' => 'Picture + text', 'group' => 'Story', 'icon' => 'heart', 'needs' => [],
                'description' => 'A picture next to a title, text and a button.',
                'fields' => [
                    'title' => $title,
                    'text' => ['type' => 'textarea', 'label' => 'Text', 'max' => 1200],
                    'image' => ['type' => 'image', 'label' => 'Picture'],
                    'image_side' => ['type' => 'select', 'label' => 'Picture side', 'options' => ['left' => 'Left', 'right' => 'Right']],
                    ...$button(1),
                    'accent' => $accent,
                ],
                'defaults' => ['title' => 'Made to last', 'text' => 'Tell people what makes your pieces special.', 'image' => null, 'image_side' => 'left',
                    'button1_text' => '', 'button1_link' => '', 'accent' => 'none'],
            ],
            'numbers' => [
                'label' => 'Trust numbers', 'group' => 'Explain', 'icon' => 'check', 'needs' => [],
                'description' => 'Live numbers from your real data. A number that is 0 is not shown.',
                'fields' => [
                    'title' => $title,
                    'stats' => ['type' => 'checks', 'label' => 'Numbers to show', 'options' => HomeNumbers::LABELS],
                ],
                'defaults' => ['title' => '', 'stats' => ['products', 'customers', 'cities']],
            ],
            'faq' => [
                'label' => 'Questions (FAQ)', 'group' => 'Explain', 'icon' => 'chat', 'needs' => [],
                'description' => 'Questions from your Help centre, or new ones you write here.',
                'fields' => [
                    'title' => $title,
                    'pick' => ['type' => 'faqs', 'label' => 'From the Help centre', 'max' => 12],
                    'items' => ['type' => 'list', 'label' => 'My own questions', 'max' => 12, 'fields' => [
                        'question' => ['type' => 'text', 'label' => 'Question', 'max' => 200],
                        'answer' => ['type' => 'textarea', 'label' => 'Answer', 'max' => 1200],
                    ]],
                ],
                'defaults' => ['title' => 'Questions', 'pick' => [], 'items' => [
                    ['question' => 'How do I pay?', 'answer' => 'By bank transfer or Telebirr. You send a photo of the receipt and we check it.'],
                ]],
            ],
            'cta' => [
                'label' => 'Call to action band', 'group' => 'Explain', 'icon' => 'star', 'needs' => [],
                'description' => 'A coloured band with a title, a line and a button.',
                'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Title', 'max' => 120, 'required' => true],
                    'text' => ['type' => 'textarea', 'label' => 'Short line', 'max' => 300],
                    ...$button(1),
                    'accent' => $accent,
                ],
                'defaults' => ['title' => 'Find something made by hand', 'text' => 'New pieces every week.', 'button1_text' => 'Shop now', 'button1_link' => '/shop', 'accent' => 'green'],
            ],
            'contact' => [
                'label' => 'Contact', 'group' => 'Explain', 'icon' => 'phone', 'needs' => [],
                'description' => 'WhatsApp, Telegram and email buttons, from Settings → Site.',
                'note' => 'A button shows only when its number or name is filled in Settings → Site.',
                'fields' => [
                    'title' => $title,
                    'text' => ['type' => 'textarea', 'label' => 'Short line', 'max' => 300],
                    'channels' => ['type' => 'checks', 'label' => 'Buttons', 'options' => ['whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'email' => 'Email', 'phone' => 'Phone']],
                ],
                'defaults' => ['title' => 'Talk to us', 'text' => 'Questions about a piece or an order? We answer every day.', 'channels' => ['whatsapp', 'telegram', 'email']],
            ],
            'spacer' => [
                'label' => 'Space / line', 'group' => 'Layout', 'icon' => 'check', 'needs' => [],
                'description' => 'Empty space between sections, with or without a thin line.',
                'fields' => [
                    'size' => ['type' => 'select', 'label' => 'Size', 'options' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large']],
                    'line' => ['type' => 'toggle', 'label' => 'Show a thin line'],
                ],
                'defaults' => ['size' => 'medium', 'line' => false],
            ],
        ];
    }

    /** @return ?array<string, mixed> */
    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> switches that are off for this section (empty = it can show)
     */
    public static function offFeatures(string $type, array $data = []): array
    {
        $needs = self::get($type)['needs'] ?? [];
        if ($type === 'products') {
            $needs = [...$needs, ...match ($data['source'] ?? null) {
                'members_only' => ['shop.members_only_product'], 'on_sale' => ['shop.flash_sales'], default => [],
            }];
        }

        return array_values(array_filter(array_unique($needs), fn (string $f) => Features::disabled($f)));
    }
}
