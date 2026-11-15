@extends('layouts.store')
@php
    $s = fn ($k) => \App\Support\Settings::int($k);
    $hero = $featured->first()?->images->first();
@endphp
@push('head')@include('store.partials.preload-image', ['image' => $hero, 'sizes' => '(min-width: 768px) 50vw, 100vw'])@endpush
@section('content')
<section class="bg-gradient-to-b from-mist-100 to-mist-50 dark:from-ink-800 dark:to-ink-900">
    <div class="container-km grid items-center gap-10 py-14 md:grid-cols-2 md:py-20">
        <div>
            <p class="badge mb-4">Handmade in Ethiopia</p>
            <h1 class="text-4xl leading-tight font-semibold md:text-5xl">Beautiful things, made slowly by hand.</h1>
            <p class="muted mt-4 max-w-md text-lg">Woven, carved and stitched by makers we know by name. Shipped all over Ethiopia.</p>
            <div class="mt-7 flex flex-wrap gap-3">
                @feature('shop')<a href="{{ url('/shop') }}" class="btn-primary">Shop the collection</a>@endfeature
                @feature('membership')<a href="{{ url('/membership') }}" class="btn-ghost">Become a member — save {{ $s('pricing.member_discount_bps') / 100 }}%</a>@endfeature
            </div>
        </div>
        @if ($featured->first())
            <a href="{{ url('/shop/'.$featured->first()->slug) }}" class="block aspect-[4/5] overflow-hidden rounded-[2rem] shadow-xl">
                @include('store.partials.picture', ['image' => $hero, 'eager' => true, 'sizes' => '(min-width: 768px) 50vw, 100vw'])
            </a>
        @endif
    </div>
</section>

@foreach ($banners as $b)
    <div class="container-km mt-8"><div class="card flex flex-wrap items-center justify-between gap-3">
        <div><p class="font-serif text-xl">{{ $b->title }}</p>@if ($b->text)<p class="muted">{{ $b->text }}</p>@endif</div>
        @if ($b->link_url)<a href="{{ $b->link_url }}" class="btn-dark">{{ $b->link_text ?? 'See more' }}</a>@endif
    </div></div>
@endforeach

@if ($categories->isNotEmpty())
<section class="container-km mt-12">
    <div class="flex flex-wrap gap-2">
        @foreach ($categories as $c)<a href="{{ url('/c/'.$c->slug) }}" class="btn-ghost">{{ $c->name }}</a>@endforeach
    </div>
</section>
@endif

@if ($featured->count() > 1 || $latest->isNotEmpty())
<section class="container-km mt-12">
    <div class="mb-5 flex items-end justify-between"><h2 class="text-3xl">New from the workshop</h2><a href="{{ url('/shop') }}" class="inline-flex min-h-12 items-center text-sm font-semibold text-brand-600">See all →</a></div>
    <div class="grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-4">
        @foreach ($latest as $p)@include('store.partials.product-card', ['p' => $p])@endforeach
    </div>
</section>
@endif

@if ($membersOnly)
<section class="container-km mt-16">
    <div class="grid items-center gap-8 rounded-[2rem] bg-brand-700 p-6 text-white md:grid-cols-2 dark:bg-ink-800 md:p-10">
        <div class="aspect-square overflow-hidden rounded-2xl">@include('store.partials.picture', ['image' => $membersOnly->images->first()])</div>
        <div>
            <p class="badge bg-white/15 text-white dark:bg-white/10">Members-only product of the month</p>
            <h2 class="mt-3 text-3xl">{{ $membersOnly->name }}</h2>
            <p class="mt-3 text-white/85">{{ $membersOnly->short_description }}</p>
            <a href="{{ url('/shop/'.$membersOnly->slug) }}" class="btn mt-6 bg-white text-brand-700 hover:bg-mist-100">See it</a>
        </div>
    </div>
</section>
@endif

@feature('membership')
<section class="container-km mt-16 grid gap-4 md:grid-cols-4">
    <div class="card"><p class="text-3xl font-semibold text-brand-600">{{ $s('pricing.member_discount_bps') / 100 }}%</p><p class="muted">off every product for members</p></div>
    @feature('shop.free_shipping')<div class="card"><p class="text-3xl font-semibold text-brand-600">Free</p><p class="muted">shipping over {{ \App\Support\Money::format($s('shipping.member_free_over')) }}</p></div>@endfeature
    @feature('shop.early_access')<div class="card"><p class="text-3xl font-semibold text-brand-600">{{ $s('catalog.early_access_hours') }} h</p><p class="muted">early access to new pieces</p></div>@endfeature
    @feature('signals')<div class="card"><p class="text-3xl font-semibold text-brand-600">Included</p><p class="muted">trading signals for members</p></div>@endfeature
</section>
<div class="container-km mt-6 text-center"><a href="{{ url('/membership') }}" class="btn-dark">See membership — {{ \App\Support\Money::format($s('membership.price')) }}/month</a></div>
@endfeature
@endsection
