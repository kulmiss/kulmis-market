@extends('layouts.member', ['title' => 'Wishlist'])
@section('content')
@php use App\Badges\BadgeIcons as I; $calc = app(\App\Pricing\PriceCalculator::class); @endphp
<h1 class="font-serif text-3xl font-semibold">Wishlist</h1>
<p class="mt-1 text-sm m-muted">Things you saved. Tap one to see it in the shop.</p>
@if ($items->isEmpty())
    <div class="m-card mt-5 grid place-items-center gap-2 p-8 text-center text-sm">
        {!! I::svg('heart', 28) !!}
        <p>Nothing saved yet. Tap “Save to wishlist” on any product.</p>
        <a class="m-btn mt-1" href="{{ url('/shop') }}">Go to the shop</a>
    </div>
@else
    <ul class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($items as $w)
            @php $p = $w->product; $stock = $p->availableStock(); @endphp
            <li class="m-card flex gap-3">
                <a href="{{ url('/shop/'.$p->slug) }}" class="block size-24 shrink-0 overflow-hidden rounded-xl">
                    @include('store.partials.picture', ['image' => $p->images->first(), 'class' => 'h-full w-full object-cover', 'sizes' => '96px'])
                </a>
                <div class="grid min-w-0 flex-1 content-between gap-2">
                    <div>
                        <a href="{{ url('/shop/'.$p->slug) }}" class="font-semibold hover:underline">{{ $p->name }}</a>
                        <p class="text-sm tabular-nums">{{ \App\Support\Money::format($member ? $calc->memberPrice($p->price) : $p->price) }}@if ($member) <span class="m-muted">member price</span>@endif</p>
                        @if ($stock !== null && $stock < 1)<p class="text-xs text-amber-700 dark:text-amber-300">Sold out right now</p>@endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ url('/shop/'.$p->slug) }}" class="m-btn min-h-10">View</a>
                        <form method="post" action="{{ url('/account/wishlist/'.$p->id) }}">@csrf<button class="m-btn-ghost min-h-10" aria-label="Remove {{ $p->name }} from wishlist">Remove</button></form>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
@endsection
