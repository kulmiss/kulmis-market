@extends('layouts.store', ['title' => $category?->name ?? 'Shop', 'description' => $category?->description ?? 'Handcrafted Ethiopian products — member prices shown on every item.'])
@section('content')
<div class="container-km py-10">
    <h1 class="text-4xl">{{ $category?->name ?? 'Shop' }}</h1>
    @if ($category?->description)<p class="muted mt-2 max-w-2xl">{{ $category->description }}</p>@endif
    @foreach ($banners as $b)<p class="card mt-4">{{ $b->title }} @if ($b->link_url)<a class="text-clay-600 underline" href="{{ $b->link_url }}">{{ $b->link_text ?? 'More' }}</a>@endif</p>@endforeach
    <div class="mt-6 flex flex-wrap items-center gap-2">
        <a href="{{ url('/shop') }}" class="{{ $category ? 'btn-ghost' : 'btn-dark' }}">All</a>
        @foreach ($categories as $c)<a href="{{ url('/c/'.$c->slug) }}" class="{{ $category?->id === $c->id ? 'btn-dark' : 'btn-ghost' }}">{{ $c->name }}</a>@endforeach
        <form class="ms-auto" method="get">
            <label class="sr-only" for="sort">Sort</label>
            <select id="sort" name="sort" class="input py-2" onchange="this.form.submit()">
                <option value="newest" @selected($sort === 'newest')>Newest</option>
                <option value="price_asc" @selected($sort === 'price_asc')>Price: low to high</option>
                <option value="price_desc" @selected($sort === 'price_desc')>Price: high to low</option>
            </select>
        </form>
    </div>
    @if ($products->isEmpty())
        <p class="muted mt-12 text-center">Nothing here yet — new pieces are on their way.</p>
    @else
        <div class="mt-8 grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-3 lg:grid-cols-4">
            @foreach ($products as $i => $p)@include('store.partials.product-card', ['p' => $p, 'eager' => $i < 2])@endforeach
        </div>
        <div class="mt-10">{{ $products->links() }}</div>
    @endif
</div>
@endsection
