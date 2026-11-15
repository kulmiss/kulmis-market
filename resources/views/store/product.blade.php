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
        @if ($product->category)<a href="{{ url('/c/'.$product->category->slug) }}" class="text-sm font-semibold text-brand-600">{{ $product->category->name }}</a>@endif
        <h1 class="mt-1 text-4xl">{{ $product->name }}</h1>
        <div class="mt-4 text-xl" data-price>@include('store.partials.price', ['price' => $product->price])</div>
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
                            <option value="{{ $v->id }}" @disabled(!$product->isMadeToOrder() && (int) $v->stock < 1)>{{ $v->name }}@if ($v->price) — {{ \App\Support\Money::format($v->price) }}@endif @if (!$product->isMadeToOrder() && (int) $v->stock < 1) (sold out)@endif</option>
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

        @feature('membership')
        <div class="card mt-6 text-sm">
            <p class="font-semibold">Members pay {{ \App\Support\Money::format($memberPrice) }} for this</p>
            <p class="muted mt-1">Plus free shipping over {{ \App\Support\Money::format(\App\Support\Settings::int('shipping.member_free_over')) }}, early access and signals. <a class="text-brand-600 underline" href="{{ url('/membership') }}">Join &amp; save</a></p>
        </div>
        @endfeature

        @if ($product->description)<div class="prose-km mt-8">{!! \Illuminate\Support\Str::markdown($product->description, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>@endif
    </div>
</div>
@if ($related->isNotEmpty())
<section class="container-km mt-6">
    <h2 class="mb-5 text-2xl">You may also like</h2>
    <div class="grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-4">@foreach ($related as $p)@include('store.partials.product-card', ['p' => $p])@endforeach</div>
</section>
@endif
@endsection
