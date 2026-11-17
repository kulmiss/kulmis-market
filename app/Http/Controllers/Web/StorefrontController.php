<?php

namespace App\Http\Controllers\Web;

use App\Home\HomePageView;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Category;
use App\Models\HomePageVersion;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\Signal;
use App\Models\User;
use App\Pricing\PriceCalculator;
use App\Referral\Engagement;
use App\Shop\Bundles;
use App\Shop\FlashSales;
use App\Shop\Recommendations;
use App\Signals\SignalStats;
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
        // The page built in Admin → Home page, once one is published (switch off: the fixed page below).
        if (Features::enabled('site.home_builder') && ($built = HomePageVersion::live())) {
            return HomePageView::make($built->sections, $built->seo, $member);
        }

        return view('store.home', [
            'featured' => Product::query()->visibleTo($member)->where('is_featured', true)->with(['images', 'variants'])->latest('id')->limit(8)->get(),
            'latest' => Product::query()->visibleTo($member)->with(['images', 'variants'])->latest('release_at')->latest('id')->limit(8)->get(),
            'membersOnly' => Features::enabled('shop.members_only_product') || ($member && Features::memberBenefit('shop.members_only_product'))
                ? Product::query()->visibleTo($member)->where('is_members_only', true)->with(['images', 'variants'])->latest('id')->first() : null,
            'categories' => Category::query()->where('is_active', true)->orderBy('position')->limit(8)->get(),
            'banners' => Banner::query()->live('home')->get()->filter(fn (Banner $b) => $b->shownNow())->values(),
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
            'banners' => Banner::query()->live('shop')->get()->filter(fn (Banner $b) => $b->shownNow())->values(),
        ]);
    }

    public function product(Request $request, string $slug): View
    {
        abort_if(Features::disabled('shop'), 404);
        $member = $this->isMember($request);
        $product = Product::query()->visibleTo($member)->where('slug', $slug)->with(['images', 'variants', 'category', 'tags'])->firstOrFail();
        $together = collect();
        if (Features::enabled('shop.upsells')) {
            $reco = app(Recommendations::class);
            $together = $reco->boughtTogether($product, $member);
            $related = $reco->alsoLike($product, $member, 4, $together->modelKeys());
        } else {
            $related = Product::query()->visibleTo($member)->where('id', '!=', $product->id)
                ->when($product->category_id, fn ($q) => $q->where('category_id', $product->category_id))
                ->with(['images', 'variants'])->inRandomOrder()->limit(4)->get();
        }

        $sale = app(FlashSales::class)->forDisplay($product);
        // What a member really pays now: the sale price when the sale is on the whole product, and only if members get a discount here.
        $base = $sale && $sale->product_variant_id === null ? (int) $sale->price : (int) $product->price;

        return view('store.product', ['product' => $product, 'related' => $related, 'together' => $together, 'isMember' => $member,
            'memberPrice' => $product->member_discount === false ? $base : app(PriceCalculator::class)->memberPrice($base),
            'sale' => $sale,
            'bundles' => app(Bundles::class)->containing($product->id)]);
    }

    public function membership(): View
    {
        abort_if(Features::disabled('membership'), 404);

        return view('store.membership', ['banners' => Banner::query()->live('membership')->get()->filter(fn (Banner $b) => $b->shownNow())->values()]);
    }

    public function referralProgram(): View
    {
        abort_if(Features::disabled('referral'), 404);

        return view('store.referral', ['top' => app(Engagement::class)->topReferrers()]);
    }

    public function signals(): View
    {
        abort_if(Features::disabled('signals'), 404);

        return view('store.signals', ['banners' => Banner::query()->live('signals')->get()->filter(fn (Banner $b) => $b->shownNow())->values(), 'stats' => app(SignalStats::class)->totals()]);
    }

    public function performance(Request $request, SignalStats $stats): View
    {
        abort_if(Features::disabled('signals') || Features::disabled('signals.performance_page'), 404);
        $pair = $stats->validPair($request->query('pair'));

        return view('store.performance', ['stats' => $stats->totals($pair), 'months' => $stats->monthly($pair),
            'pairs' => $stats->pairs(), 'pair' => $pair,
            'signals' => Signal::query()->where('status', 'closed')->when($pair, fn ($q, $p) => $q->where('pair', $p))->latest('closed_at')->limit(100)->get()]);
    }

    public function page(string $slug): View
    {
        $page = Page::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();
        abort_unless($page->shownNow(), 404);

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
        abort_if(Features::disabled('shop'), 404);
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
        abort_if(Features::disabled('shop.order_tracking'), 404);

        return view('store.track');
    }

    public function sitemap(): Response
    {
        $urls = collect([url('/'), url('/shop')])
            ->when(Features::enabled('membership'), fn ($c) => $c->push(url('/membership')))
            ->when(Features::enabled('signals'), fn ($c) => $c->push(url('/signals')))
            ->when(Features::enabled('referral'), fn ($c) => $c->push(url('/referral-program')))
            ->when(Features::enabled('site.help_centre'), fn ($c) => $c->push(url('/help')))
            ->merge(Category::query()->where('is_active', true)->pluck('slug')->map(fn ($s) => url('/c/'.$s)))
            ->merge(Product::query()->visibleTo(false)->pluck('slug')->map(fn ($s) => url('/shop/'.$s)))
            ->merge(Page::query()->where('is_published', true)->get(['slug', 'title'])->filter(fn (Page $p) => $p->shownNow())->map(fn (Page $p) => url('/p/'.$p->slug)));
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .$urls->map(fn ($u) => '<url><loc>'.e($u).'</loc></url>')->implode('').'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $body = "User-agent: *\nDisallow: /admin\nDisallow: /account\n".(Features::enabled('shop') ? "Disallow: /checkout\nDisallow: /pay/\n" : '')."Disallow: /api/\nSitemap: ".url('/sitemap.xml')."\n";

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }

    private function isMember(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->isActiveMember();
    }
}
