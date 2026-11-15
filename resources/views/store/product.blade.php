@php
    $img = $product->images->first();
    $variants = $product->variants->where('is_active', true)->values();
    $canBuy = $product->isPurchasableBy($isMember);
    $stock = $product->availableStock();
    $jsonLd = [
        '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $product->name,
        'description' => $product->short_description ?? strip_tags((string) $product->description),
        'image' => $product->images->map(fn ($i) => $i->url(min(1600, max($i->widths)), in_array('jpg', $i->formats, true) ? 'jpg' : $i->formats[0]))->all(),
        'sku' => (string) $product->id, 'brand' => ['@type' => 'Brand', 'name' => \App\Support\Settings::string('site.name')],
        'offers' => ['@type' => 'Offer', 'priceCurrency' => \App\Support\Currency::code(), 'price' => number_format($product->price / 100, 2, '.', ''),
            'availability' => $stock === null || $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', 'url' => url('/shop/'.$product->slug)],
    ];
    if ($sale && $sale->product_variant_id === null && $sale->price < $product->price) {
        $jsonLd['offers']['price'] = number_format($sale->price / 100, 2, '.', '');
        $jsonLd['offers']['priceValidUntil'] = $sale->ends_at->toDateString();
    }
    if (\App\Support\Features::enabled('shop.reviews') && $product->rating_count > 0) {
        $jsonLd['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (string) $product->rating_avg, 'reviewCount' => $product->rating_count];
    }
@endphp
@extends('layouts.store', [
    'title' => $product->seo_title ?? $product->name,
    'description' => $product->seo_description ?? $product->short_description ?? 'Handcrafted in Ethiopia.',
    'ogType' => 'product',
    'ogImage' => $img ? $img->url(in_array(1600, $img->widths, true) ? 1600 : max($img->widths), in_array('jpg', $img->formats, true) ? 'jpg' : $img->formats[0]) : null,
])
@push('head')@include('store.partials.preload-image', ['image' => $img, 'sizes' => '(min-width: 768px) 50vw, 100vw'])<script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>@endpush
@section('content')
<div class="container-km grid gap-10 py-10 md:grid-cols-2">
    <div>
        <div class="aspect-square overflow-hidden rounded-[1.75rem] bg-mist-100 dark:bg-ink-800" data-zoom>
            @include('store.partials.picture', ['image' => $img, 'eager' => true, 'sizes' => '(min-width: 768px) 50vw, 100vw', 'class' => 'h-full w-full object-cover transition-transform duration-200'])
        </div>
        @if ($product->images->count() > 1)
            <div class="mt-3 grid grid-cols-5 gap-2">
                @foreach ($product->images as $i)
                    <button type="button" class="aspect-square overflow-hidden rounded-xl" data-thumb="{{ $i->url(in_array(1600, $i->widths, true) ? 1600 : max($i->widths), in_array('webp', $i->formats, true) ? 'webp' : 'jpg') }}" aria-label="Show photo {{ $loop->iteration }}">
                        @include('store.partials.picture', ['image' => $i, 'sizes' => '96px'])
                    </button>
                @endforeach
            </div>
        @endif
    </div>
    <div>
        @if ($product->category)<a href="{{ url('/c/'.$product->category->slug) }}" class="inline-flex min-h-12 items-center text-sm font-semibold text-brand-600 sm:min-h-0">{{ $product->category->name }}</a>@endif
        <h1 class="mt-1 text-4xl">{{ $product->name }}</h1>
        <div class="mt-4 text-xl" data-price>@include('store.partials.price', ['price' => $product->price, 'sale' => $sale && $sale->product_variant_id === null ? $sale : null])</div>
        @if ($sale)
            <div class="mt-3 rounded-2xl border border-mist-200 p-4 dark:border-ink-700" data-flash-sale>
                <p class="font-semibold">Flash sale{{ $sale->variant ? ' on '.$sale->variant->name : '' }}: {{ \App\Support\Money::format($sale->price) }}</p>
                <p class="muted mt-1 text-sm">
                    Ends in <span class="font-semibold tabular-nums" data-countdown="{{ $sale->ends_at->toIso8601String() }}">{{ $sale->ends_at->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                    @if ($sale->stock_limit) · only {{ $sale->stock_limit }} pieces at this price @endif
                    @if (\App\Support\Features::enabled('membership')) · members pay {{ \App\Support\Money::format(app(\App\Pricing\PriceCalculator::class)->memberPrice($sale->price)) }} @endif
                </p>
            </div>
        @endif
        <div class="mt-3 flex flex-wrap gap-2">
            @if ($product->is_members_only)<span class="badge-gold">Members-only product</span>@endif
            @if ($product->inEarlyAccess())<span class="badge">Members early access — public on {{ $product->release_at->timezone(config('app.timezone'))->format('d M') }}</span>@endif
            @if ($product->isMadeToOrder())<span class="badge">Made to order · about {{ $product->making_days }} days</span>
            @elseif ($stock !== null && $stock > 0 && $stock <= 3)<span class="badge">Only {{ $stock }} left</span>@endif
        </div>
        @if ($product->short_description)<p class="muted mt-5 text-lg">{{ $product->short_description }}</p>@endif

        <form class="mt-6 grid gap-4" data-add-to-cart data-product="{{ $product->id }}">
            @if ($variants->isNotEmpty())
                <div>
                    <label class="label" for="variant">Choose</label>
                    <select id="variant" name="variant_id" class="input" required>
                        @foreach ($variants as $v)
                            @php $vSale = app(\App\Shop\FlashSales::class)->for($product, $v); $vPrice = $v->price ?? $product->price; @endphp
                            <option value="{{ $v->id }}" @disabled(!$product->isMadeToOrder() && (int) $v->stock < 1)>{{ $v->name }}@if ($vSale && $vSale->price < $vPrice) — sale {{ \App\Support\Money::format($vSale->price) }} (was {{ \App\Support\Money::format($vPrice) }})@elseif ($v->price) — {{ \App\Support\Money::format($v->price) }}@endif @if (!$product->isMadeToOrder() && (int) $v->stock < 1) (sold out)@endif</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="flex items-center gap-3">
                <label class="sr-only" for="qty">Quantity</label>
                <input id="qty" name="quantity" type="number" min="1" max="99" value="1" class="input w-24">
                @if ($canBuy && ($stock === null || $stock > 0))
                    <button class="btn-primary flex-1">Add to cart</button>
                @elseif ($product->is_members_only)
                    <a href="{{ url('/membership') }}" class="btn-dark flex-1">Join to buy this piece</a>
                @else
                    <button class="btn-ghost flex-1" disabled>Sold out</button>
                @endif
            </div>
            <p data-cart-message class="text-sm" role="status" aria-live="polite"></p>
        </form>

        @feature('shop.wishlist')
            @auth
                @php $saved = \App\Models\WishlistItem::query()->where('user_id', auth()->id())->where('product_id', $product->id)->exists(); @endphp
                <form method="post" action="{{ url('/account/wishlist/'.$product->id) }}" class="mt-3">@csrf
                    <button class="btn-ghost w-full" aria-pressed="{{ $saved ? 'true' : 'false' }}">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="{{ $saved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                        {{ $saved ? 'Saved in your wishlist' : 'Save to wishlist' }}
                    </button>
                </form>
            @else
                <a href="{{ url('/login') }}" class="btn-ghost mt-3 w-full">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                    Sign in to save it
                </a>
            @endauth
        @endfeature

        @if (auth()->check() && \App\Support\Features::enabled('referral') && \App\Support\Features::enabled('referral.product_links') && auth()->user()->referral_code)
            @php
                $me = auth()->user();
                $shareLink = \App\Referral\ShareLinks::for($me, 'shop/'.$product->slug);
                $shareText = $product->name.' — '.\App\Support\Money::format($sale && $sale->product_variant_id === null ? $sale->price : $product->price).' at '.\App\Support\Settings::string('site.name').'. Code '.$me->referral_code.':';
                $img = $product->images->first();
            @endphp
            <details class="card mt-4" data-product-share>
                <summary class="flex min-h-12 cursor-pointer items-center justify-between font-semibold">Share and earn <span class="muted text-sm font-normal">your link for this product</span></summary>
                <div class="mt-3 km-member" style="--m-accent: var(--color-brand-600)">
                    @include('member.partials.share-card', ['text' => $shareText.' '.$shareLink, 'buttons' => \App\Referral\ShareLinks::buttons($shareLink, $shareText),
                        'image' => $img ? $img->url(in_array(1200, $img->widths, true) ? 1200 : max($img->widths), in_array('jpg', $img->formats, true) ? 'jpg' : $img->formats[0]) : asset('og-default.png'),
                        'title' => $product->name, 'line' => \App\Support\Money::format($sale && $sale->product_variant_id === null ? $sale->price : $product->price),
                        'code' => $me->referral_code, 'link' => $shareLink,
                        'qr' => \App\Support\Features::enabled('referral.qr_codes') ? url('/account/referral-qr.svg').'?to=shop/'.$product->slug : ''])
                </div>
            </details>
        @endif

        @feature('shop.stock_alerts')
            @php $soldOutVariants = $variants->filter(fn ($v) => ! $product->isMadeToOrder() && (int) $v->stock < 1); @endphp
            @if (! $product->isMadeToOrder() && (($variants->isEmpty() && (int) $stock < 1) || $soldOutVariants->isNotEmpty()))
                <form class="card mt-4 grid gap-3" data-stock-alert data-product="{{ $product->id }}" novalidate>
                    <p class="font-semibold">{{ $variants->isEmpty() ? 'Sold out right now' : 'Some options are sold out' }}</p>
                    <p class="muted -mt-2 text-sm">Leave your phone or email. We send you <b>one</b> message when it is back.</p>
                    @if ($soldOutVariants->isNotEmpty())
                        <label><span class="label">Which one?</span>
                            <select name="variant_id" class="input">@foreach ($soldOutVariants as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach</select>
                        </label>
                    @endif
                    <label><span class="label">Phone or email</span>
                        <input name="contact" class="input" required maxlength="160" autocomplete="email" placeholder="09… or you@example.com" value="{{ auth()->user()?->phone }}">
                    </label>
                    <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
                    <button class="btn-dark">Tell me when it's back</button>
                    <p data-alert-message class="text-sm" role="status" aria-live="polite"></p>
                </form>
            @endif
        @endfeature

        @feature('membership')
        <div class="card mt-6 text-sm">
            <p class="font-semibold">Members pay {{ \App\Support\Money::format($memberPrice) }} for this</p>
            <p class="muted mt-1">Plus free shipping over {{ \App\Support\Money::format(\App\Support\Settings::int('shipping.member_free_over')) }}, early access and signals. <a class="text-brand-600 underline" href="{{ url('/membership') }}">Join &amp; save</a></p>
        </div>
        @endfeature

        @if ($product->description)<div class="prose-km mt-8">{!! \Illuminate\Support\Str::markdown($product->description, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>@endif
    </div>
</div>
@if ($bundles->isNotEmpty())
<section class="container-km mt-6" aria-labelledby="bundles-h">
    <h2 id="bundles-h" class="mb-4 text-2xl">Save with a set</h2>
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($bundles as $b)
            <div class="card grid gap-3">
                <p class="font-semibold">{{ $b->name }}</p>
                @if ($b->description)<p class="muted -mt-2 text-sm">{{ $b->description }}</p>@endif
                <ul class="grid gap-1 text-sm">
                    @foreach ($b->items as $i)<li>{{ $i->quantity }} × <a class="underline" href="{{ url('/shop/'.$i->product->slug) }}">{{ $i->product->name }}{{ $i->variant ? ' — '.$i->variant->name : '' }}</a></li>@endforeach
                </ul>
                <div>@include('store.partials.price', ['price' => $b->price])</div>
                <p class="muted -mt-2 text-sm">One by one: {{ \App\Support\Money::format($b->regularPrice()) }} · you save {{ \App\Support\Money::format($b->regularPrice() - $b->price) }}</p>
                <button type="button" class="btn-dark" data-add-bundle="{{ $b->id }}">Add the set to cart</button>
                <p class="text-sm" data-bundle-message role="status" aria-live="polite"></p>
            </div>
        @endforeach
    </div>
</section>
@endif
@feature('shop.reviews')@include('store.partials.reviews')@endfeature
@if ($together->isNotEmpty())
<section class="container-km mt-6">
    <h2 class="mb-5 text-2xl">Frequently bought together</h2>
    <div class="grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-4">@foreach ($together as $p)@include('store.partials.product-card', ['p' => $p])@endforeach</div>
</section>
@endif
@if ($related->isNotEmpty())
<section class="container-km mt-6">
    <h2 class="mb-5 text-2xl">You may also like</h2>
    <div class="grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-4">@foreach ($related as $p)@include('store.partials.product-card', ['p' => $p])@endforeach</div>
</section>
@endif
@endsection
