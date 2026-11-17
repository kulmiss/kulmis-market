<?php

namespace App\Home;

use App\Demo\Demo;
use App\Help\HelpTokens;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Faq;
use App\Models\HomeMedia;
use App\Models\Order;
use App\Models\Product;
use App\Models\Testimonial;
use App\Shop\FlashSales;
use App\Support\Features;
use App\Support\FeatureText;
use App\Support\FeatureUrls;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns saved sections into what the Blade partials show. Only the data a
 * section needs is loaded. A section is left out when it is switched off,
 * its feature is off, or it has nothing to show (no products, no sale…).
 *
 * Every text goes through the live numbers ({membership_price}…) and the
 * {if feature} blocks; a text that still talks about a switched-off feature
 * is left out (like FAQ answers), so off features never show.
 */
final class HomeRenderer
{
    /** @var array<string, string> */
    private array $tokens;

    /** @var Collection<int, HomeMedia> */
    private Collection $media;

    private bool $heroSeen = false;

    public function __construct(private readonly bool $member = false, private readonly bool $preview = false)
    {
        $this->tokens = [];
        foreach (HelpTokens::all() as $k => [$v]) {
            $this->tokens['{'.$k.'}'] = $v;
        }
        $this->media = collect();
    }

    /**
     * @param  list<array{id:string, type:string, on:bool, data:array<string, mixed>}>  $sections
     * @return list<array{id:string, type:string, d:array<string, mixed>, x:array<string, mixed>}>
     */
    public function prepare(array $sections): array
    {
        $this->media = HomeMedia::query()->whereIn('id', Sections::mediaIds($sections))->get()->keyBy('id');
        $out = [];
        foreach ($sections as $s) {
            if (! $s['on'] || SectionTypes::offFeatures($s['type'], $s['data']) !== []) {
                continue;
            }
            $x = $this->context($s['type'], $s['data']);
            if ($x !== null) {
                $out[] = ['id' => $s['id'], 'type' => $s['type'], 'd' => $s['data'], 'x' => $x];
            }
        }

        return $out;
    }

    public function media(?int $id, string $kind = 'image'): ?HomeMedia
    {
        $m = $id === null ? null : $this->media->get($id);

        return $m?->kind === $kind ? $m : null;
    }

    /** A text with live numbers; empty when it still mentions a switched-off feature. */
    public function text(mixed $v): string
    {
        $s = strtr(FeatureText::apply((string) $v), $this->tokens);

        return $s !== '' && FeatureText::mentionsOff($s) !== [] ? '' : $s;
    }

    /** @return ?array{text:string, href:string} a button only when it has a text and a link to a page that is there */
    public function button(mixed $text, mixed $link): ?array
    {
        $text = $this->text($text);
        $href = SafeLink::href((string) $link);
        if ($text === '' || $href === null || ! FeatureUrls::available($href)) {
            return null;
        }

        return ['text' => $text, 'href' => $href];
    }

    /** @return array{class:string, style:?string, onDark:bool, filled:bool} onDark: light text on a dark colour */
    public static function accent(string $accent, string $default = 'none'): array
    {
        if (preg_match('/^#[0-9a-f]{6}$/', $accent)) {
            $text = Contrast::best($accent)[0];

            return ['class' => '', 'style' => "background-color:$accent;color:$text", 'onDark' => $text === Contrast::LIGHT, 'filled' => true];
        }
        $key = isset(SectionTypes::ACCENTS[$accent]) ? $accent : $default;

        return ['class' => SectionTypes::ACCENTS[$key], 'style' => null, 'onDark' => in_array($key, ['green', 'gold', 'dark'], true), 'filled' => $key !== 'none'];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return ?array<string, mixed>
     */
    private function context(string $type, array $d): ?array
    {
        return match ($type) {
            'hero' => $this->hero($d),
            'announcement' => ($t = $this->text($d['text'])) === '' ? null
                : ['text' => $t, 'button' => $this->button($d['link_text'], $d['link']), 'accent' => self::accent($d['accent'], 'dark')],
            'banners' => ($b = Banner::query()->live('home')->get()->filter(fn (Banner $b) => $b->shownNow())->values())->isEmpty() ? null : ['banners' => $b],
            'categories' => $this->categories($d),
            'products' => $this->products($d),
            'flash_sale' => $this->flashSale($d),
            'membership' => $this->membership($d),
            'signals', 'cta' => ($t = $this->text($d['title'])) === '' && $type === 'cta' ? null
                : ['title' => $t, 'text' => $this->text($d['text']), 'button' => $this->button($d['button1_text'], $d['button1_link']),
                    'accent' => self::accent($d['accent'], $type === 'cta' ? 'green' : 'light')],
            'how_it_works' => ($steps = self::rows($d['steps'])->map(fn ($s) => ['icon' => $s['icon'], 'title' => $this->text($s['title']), 'text' => $this->text($s['text'])])
                ->filter(fn ($s) => $s['title'] !== '')->values())->isEmpty() ? null : ['title' => $this->text($d['title']), 'steps' => $steps],
            'how_you_earn' => $this->howYouEarn($d),
            'stories' => ($items = self::rows($d['items'])->map(fn ($i) => [
                'image' => $this->media($i['image']), 'name' => $this->text($i['name']), 'place' => $this->text($i['place']),
                'text' => $this->text($i['text']), 'button' => $this->button($i['link_text'], $i['link']),
            ])->filter(fn ($i) => $i['name'] !== '' || $i['text'] !== '')->values())->isEmpty() ? null : ['title' => $this->text($d['title']), 'items' => $items],
            'image_text' => ($t = $this->text($d['title'])) === '' && $this->text($d['text']) === '' ? null : [
                'title' => $t, 'text' => $this->text($d['text']), 'image' => $this->media($d['image']),
                'button' => $this->button($d['button1_text'], $d['button1_link']), 'accent' => self::accent($d['accent']),
            ],
            'numbers' => ($n = array_intersect_key(HomeNumbers::all(), array_flip($d['stats']))) === [] ? null
                : ['title' => $this->text($d['title']), 'numbers' => self::rows($d['stats'])->filter(fn ($k) => isset($n[$k]))->map(fn ($k) => ['value' => $n[$k], 'label' => HomeNumbers::LABELS[$k]])->values()],
            'faq' => $this->faq($d),
            'testimonials' => $this->testimonials($d),
            'video' => $this->video($d),
            'gallery' => ($pics = self::rows($d['items'])->map(fn ($i) => ['image' => $this->media($i['image']), 'caption' => $this->text($i['caption'])])
                ->filter(fn ($i) => $i['image'] !== null)->values())->isEmpty() ? null : ['title' => $this->text($d['title']), 'items' => $pics],
            'contact' => $this->contact($d),
            'spacer' => [],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $d
     * @return ?array<string, mixed>
     */
    private function hero(array $d): ?array
    {
        $headline = $this->text($d['headline']);
        if ($headline === '') {
            return null;
        }
        $product = null;
        $image = null;
        if ($d['image_source'] === 'featured' && Features::enabled('shop')) {
            $product = Product::query()->visibleTo($this->member)->where('is_featured', true)->with('images')->latest('id')->first();
            $image = $product?->images->first();
        } elseif ($d['image_source'] === 'image') {
            $image = $this->media($d['image']);
        }
        $level = $this->heroSeen ? 'h2' : 'h1';
        $this->heroSeen = true;

        return [
            'level' => $level, 'label' => $this->text($d['label']), 'headline' => $headline, 'subline' => $this->text($d['subline']),
            // Keyed by place: button 1 is always the strong one, button 2 the light one.
            'buttons' => array_filter([1 => $this->button($d['button1_text'], $d['button1_link']), 2 => $this->button($d['button2_text'], $d['button2_link'])]),
            'product' => $product, 'image' => $image, 'accent' => self::accent($d['accent'], 'soft'),
            'eager' => $level === 'h1',
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return ?array<string, mixed>
     */
    private function categories(array $d): ?array
    {
        $q = Category::query()->where('is_active', true);
        $cats = $d['pick'] !== []
            ? $q->whereIn('id', $d['pick'])->get()->sortBy(fn (Category $c) => array_search($c->id, $d['pick'], true))->values()->take($d['limit'])
            : $q->orderBy('position')->limit($d['limit'])->get();

        return $cats->isEmpty() ? null : ['title' => $this->text($d['title']), 'categories' => $cats];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return ?array<string, mixed>
     */
    private function products(array $d): ?array
    {
        $count = $d['layout'] === 'spotlight' ? 1 : $d['count'];
        $base = fn (): Builder => Product::query()->visibleTo($this->member)->with(['images', 'variants']);
        $inOrder = function (array $ids) use ($base, $count) {
            $rows = $base()->whereIn('id', $ids)->get()->keyBy('id');

            return collect($ids)->map(fn ($id) => $rows->get($id))->filter()->values()->take($count);
        };
        $products = match ($d['source']) {
            'featured' => $base()->where('is_featured', true)->latest('id')->limit($count)->get(),
            'members_only' => Features::enabled('shop.members_only_product') || ($this->member && Features::memberBenefit('shop.members_only_product'))
                ? $base()->where('is_members_only', true)->latest('id')->limit($count)->get() : collect(),
            'on_sale' => $inOrder(app(FlashSales::class)->live()->pluck('product_id')->unique()->values()->all()),
            'best_sellers' => $inOrder(DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('orders.status', [Order::PAID, Order::MAKING, Order::SHIPPED, Order::DELIVERED])
                ->where('orders.created_at', '>=', now()->subDays(90))->whereNotNull('order_items.product_id')
                ->when(! Demo::visible(), fn ($q) => $q->where('orders.is_demo', false))
                ->groupBy('order_items.product_id')->orderByRaw('SUM(order_items.quantity) DESC')->limit(48)->pluck('order_items.product_id')->map(fn ($id) => (int) $id)->all()),
            'picked' => $inOrder($d['picked']),
            default => $base()->latest('release_at')->latest('id')->limit($count)->get(),
        };

        return $products->isEmpty() ? null : ['title' => $this->text($d['title']), 'products' => $products];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return ?array<string, mixed>
     */
    private function flashSale(array $d): ?array
    {
        $sales = app(FlashSales::class)->live();
        if ($sales->isEmpty()) {
            return null;
        }
        $ids = $sales->pluck('product_id')->unique()->values()->all();
        $rows = Product::query()->visibleTo($this->member)->with(['images', 'variants'])->whereIn('id', $ids)->get()->keyBy('id');
        $products = collect($ids)->map(fn ($id) => $rows->get($id))->filter()->values()->take($d['count']);
        if ($products->isEmpty()) {
            return null;
        }

        return ['title' => $this->text($d['title']), 'text' => $this->text($d['text']), 'products' => $products,
            'ends' => $sales->whereIn('product_id', $products->pluck('id')->all())->min('ends_at')];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function membership(array $d): array
    {
        return [
            'title' => $this->text($d['title']), 'text' => $this->text($d['text']), 'benefits' => $d['show_benefits'],
            'discount' => Settings::int('pricing.member_discount_bps') / 100,
            'free_over' => Money::format(Settings::int('shipping.member_free_over')),
            'early_hours' => Settings::int('catalog.early_access_hours'),
            'button' => $this->button($d['button_text'], '/membership'),
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return ?array<string, mixed>
     */
    private function howYouEarn(array $d): ?array
    {
        $price = Settings::int('membership.price');
        $rate = fn (int $l) => Features::enabled("referral.level$l") ? intdiv($price * Settings::int("referral.rate.membership.l$l"), 10_000) : 0;
        if ($rate(1) <= 0) {
            return null;
        }

        return [
            'title' => $this->text($d['title']), 'text' => $this->text($d['text']), 'button' => $this->button($d['button1_text'], $d['button1_link']),
            'price' => Money::format($price), 'one' => Money::format($rate(1)), 'three' => Money::format(3 * $rate(1)),
            'l2' => $rate(2) > 0 ? Money::format($rate(2)) : null, 'l3' => $rate(3) > 0 ? Money::format($rate(3)) : null,
            'pending_days' => Settings::int('referral.pending_days'),
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return ?array<string, mixed>
     */
    private function faq(array $d): ?array
    {
        $picked = $d['pick'] === [] ? collect() : Faq::query()->where('is_published', true)->whereIn('id', $d['pick'])->get()
            ->filter(fn (Faq $f) => $f->shownNow())->sortBy(fn (Faq $f) => array_search($f->id, $d['pick'], true))
            ->map(fn (Faq $f) => ['q' => $this->text($f->question), 'a' => $this->text($f->answerNow())]);
        $own = self::rows($d['items'])->map(fn ($i) => ['q' => $this->text($i['question']), 'a' => $this->text($i['answer'])]);
        $items = $picked->concat($own)->filter(fn ($i) => $i['q'] !== '' && $i['a'] !== '')->values();

        return $items->isEmpty() ? null : ['title' => $this->text($d['title']), 'items' => $items];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return ?array<string, mixed>
     */
    private function contact(array $d): ?array
    {
        $wa = preg_replace('/\D/', '', Settings::string('site.whatsapp') ?: Settings::string('site.support_phone'));
        $tg = ltrim(Settings::string('site.telegram_support'), '@');
        $email = Settings::string('site.support_email');
        $phone = preg_replace('/[^\d+]/', '', Settings::string('site.support_phone'));
        $all = [
            'whatsapp' => $wa !== '' ? ['WhatsApp', 'https://wa.me/'.$wa] : null,
            'telegram' => preg_match('/^[A-Za-z0-9_]{4,32}$/', $tg) ? ['Telegram', 'https://t.me/'.$tg] : null,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? ['Email', 'mailto:'.$email] : null,
            'phone' => $phone !== '' ? ['Call '.Settings::string('site.support_phone'), 'tel:'.$phone] : null,
        ];
        $buttons = self::rows($d['channels'])->map(fn ($k) => $all[$k] ?? null)->filter()->map(fn ($b) => ['text' => $b[0], 'href' => $b[1]])->values();

        return $buttons->isEmpty() ? null : ['title' => $this->text($d['title']), 'text' => $this->text($d['text']), 'buttons' => $buttons];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return ?array<string, mixed>
     */
    private function testimonials(array $d): ?array
    {
        $q = Testimonial::query()->showable();
        $rows = $d['pick'] !== []
            ? $q->whereIn('id', $d['pick'])->get()->sortBy(fn (Testimonial $t) => array_search($t->id, $d['pick'], true))->values()
            : $q->orderBy('position')->orderByDesc('id')->get();
        $items = $rows->map(fn (Testimonial $t) => ['text' => $this->text($t->text), 'name' => $t->name, 'city' => (string) $t->city, 'photo' => $t->photoUrl(), 'demo' => $t->is_demo])
            ->filter(fn ($t) => $t['text'] !== '')->take($d['count'])->values();

        return $items->isEmpty() ? null : ['title' => $this->text($d['title']), 'items' => $items];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return ?array<string, mixed>
     */
    private function video(array $d): ?array
    {
        $poster = $this->media($d['poster']);
        if ($d['source'] === 'upload') {
            $file = $this->media($d['video'], 'video');

            return $file ? ['title' => $this->text($d['title']), 'caption' => $this->text($d['caption']), 'file' => $file, 'poster' => $poster, 'link' => null] : null;
        }
        $link = VideoLink::parse($d['url']);

        return $link ? ['title' => $this->text($d['title']), 'caption' => $this->text($d['caption']), 'file' => null, 'poster' => $poster, 'link' => $link] : null;
    }

    /** @return Collection<int, mixed> */
    private static function rows(mixed $v): Collection
    {
        return collect(is_array($v) ? array_values($v) : []);
    }

    public function isPreview(): bool
    {
        return $this->preview;
    }
}
