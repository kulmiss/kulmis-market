<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\Signal;
use App\Models\User;
use App\Pricing\PriceCalculator;
use App\Referral\Engagement;
use App\Support\Features;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public pages. Guests get the same cached HTML; signed-in members get a
 * live render (early access, member-only buy buttons).
 */
class StorefrontController extends Controller
{
    public function home(Request $request): View
    {
        $member = $this->isMember($request);

        return view('store.home', [
            'featured' => Product::query()->visibleTo($member)->where('is_featured', true)->with(['images', 'variants'])->latest('id')->limit(8)->get(),
            'latest' => Product::query()->visibleTo($member)->with(['images', 'variants'])->latest('release_at')->latest('id')->limit(8)->get(),
            'membersOnly' => Features::enabled('shop.members_only_product')
                ? Product::query()->visibleTo($member)->where('is_members_only', true)->with(['images', 'variants'])->latest('id')->first() : null,
            'categories' => Category::query()->where('is_active', true)->orderBy('position')->limit(8)->get(),
            'banners' => Banner::query()->live('home')->get(),
            'isMember' => $member,
        ]);
    }

    public function shop(Request $request, ?string $category = null): View
    {
        abort_if(Features::disabled('shop'), 404);
        $member = $this->isMember($request);
        $cat = $category ? Category::query()->where('slug', $category)->where('is_active', true)->firstOrFail() : null;
        $sort = (string) $request->query('sort', 'newest');
        $products = Product::query()->visibleTo($member)->with(['images', 'variants'])
            ->when($cat, fn ($q) => $q->where('category_id', $cat->id))
            ->when($request->query('q'), fn ($q, $v) => $q->where('name', 'like', '%'.mb_substr((string) $v, 0, 60).'%'))
            ->tap(fn ($q) => match ($sort) {
                'price_asc' => $q->orderBy('price'), 'price_desc' => $q->orderByDesc('price'), default => $q->latest('release_at')->latest('id'),
            })
            ->paginate(24)->withQueryString();

        return view('store.shop', [
            'products' => $products, 'category' => $cat, 'sort' => $sort, 'isMember' => $member,
            'categories' => Category::query()->where('is_active', true)->orderBy('position')->get(),
            'banners' => Banner::query()->live('shop')->get(),
        ]);
    }

    public function product(Request $request, string $slug): View
    {
        abort_if(Features::disabled('shop'), 404);
        $member = $this->isMember($request);
        $product = Product::query()->visibleTo($member)->where('slug', $slug)->with(['images', 'variants', 'category', 'tags'])->firstOrFail();
        $related = Product::query()->visibleTo($member)->where('id', '!=', $product->id)
            ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
            ->with(['images', 'variants'])->inRandomOrder()->limit(4)->get();

        return view('store.product', ['product' => $product, 'related' => $related, 'isMember' => $member,
            'memberPrice' => app(PriceCalculator::class)->memberPrice($product->price)]);
    }

    public function membership(): View
    {
        abort_if(Features::disabled('membership'), 404);

        return view('store.membership', ['banners' => Banner::query()->live('membership')->get()]);
    }

    public function referralProgram(): View
    {
        abort_if(Features::disabled('referral'), 404);

        return view('store.referral', ['top' => app(Engagement::class)->topReferrers()]);
    }

    public function signals(): View
    {
        abort_if(Features::disabled('signals'), 404);

        return view('store.signals', ['banners' => Banner::query()->live('signals')->get(), 'stats' => $this->signalStats()]);
    }

    public function performance(): View
    {
        abort_if(Features::disabled('signals') || Features::disabled('signals.performance_page'), 404);

        return view('store.performance', ['stats' => $this->signalStats(),
            'signals' => Signal::query()->where('status', 'closed')->latest('closed_at')->limit(100)->get()]);
    }

    public function page(string $slug): View
    {
        $page = Page::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();

        return view('store.page', ['page' => $page]);
    }

    public function cart(): View
    {
        abort_if(Features::disabled('shop'), 404);

        return view('store.cart');
    }

    public function checkout(Request $request): View
    {
        abort_if(Features::disabled('shop'), 404);
        $user = $request->user();

        return view('store.checkout', ['user' => $user instanceof User ? $user : null,
            'guestAllowed' => Features::enabled('shop.guest_checkout')]);
    }

    public function pay(Request $request, string $number): View
    {
        $user = $request->user();
        $order = Order::query()->where('number', strtoupper($number))->first();
        $phone = Phone::normalize((string) $request->query('phone'));
        $owner = $order && (($user instanceof User && $order->user_id === $user->id) || ($phone && $order->phone === $phone));
        abort_unless($owner, 404);

        return view('store.pay', ['order' => $order, 'phone' => $order->phone,
            'bank' => Settings::string('payments.bank_details'), 'telebirr' => Settings::string('payments.telebirr_details')]);
    }

    public function track(): View
    {
        return view('store.track');
    }

    public function sitemap(): Response
    {
        $urls = collect([url('/'), url('/shop')])
            ->when(Features::enabled('membership'), fn ($c) => $c->push(url('/membership')))
            ->when(Features::enabled('signals'), fn ($c) => $c->push(url('/signals')))
            ->when(Features::enabled('referral'), fn ($c) => $c->push(url('/referral-program')))
            ->merge(Category::query()->where('is_active', true)->pluck('slug')->map(fn ($s) => url('/c/'.$s)))
            ->merge(Product::query()->visibleTo(false)->pluck('slug')->map(fn ($s) => url('/shop/'.$s)))
            ->merge(Page::query()->where('is_published', true)->pluck('slug')->map(fn ($s) => url('/p/'.$s)));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$urls->map(fn ($u) => '<url><loc>'.e($u).'</loc></url>')->implode('').'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $body = "User-agent: *\nDisallow: /admin\nDisallow: /account\nDisallow: /checkout\nDisallow: /pay/\nDisallow: /api/\nSitemap: ".url('/sitemap.xml')."\n";

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }

    /** @return array{wins:int, losses:int, breakeven:int, closed:int, win_rate:?float} */
    private function signalStats(): array
    {
        $q = Signal::query()->where('status', 'closed');
        $wins = (clone $q)->where('result', 'win')->count();
        $losses = (clone $q)->where('result', 'loss')->count();
        $be = (clone $q)->where('result', 'breakeven')->count();

        return ['wins' => $wins, 'losses' => $losses, 'breakeven' => $be, 'closed' => $wins + $losses + $be,
            'win_rate' => $wins + $losses > 0 ? round($wins * 100 / ($wins + $losses), 1) : null];
    }

    private function isMember(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->isActiveMember();
    }
}
