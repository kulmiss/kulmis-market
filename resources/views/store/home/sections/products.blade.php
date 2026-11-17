@if ($d['layout'] === 'spotlight')
@php($p = $x['products']->first())
<section class="container-km mt-16">
    <div class="grid items-center gap-8 rounded-[2rem] bg-brand-700 p-6 text-white md:grid-cols-2 dark:bg-ink-800 md:p-10">
        <div class="aspect-square overflow-hidden rounded-2xl">@include('store.partials.picture', ['image' => $p->images->first()])</div>
        <div>
            @if ($x['title'] !== '')<p class="badge bg-white/15 text-white dark:bg-white/10">{{ $x['title'] }}</p>@endif
            <h2 class="mt-3 text-3xl">{{ $p->name }}</h2>
            <p class="mt-3 text-white/85">{{ $p->short_description }}</p>
            <a href="{{ url('/shop/'.$p->slug) }}" class="btn mt-6 bg-white text-brand-700 hover:bg-mist-100">See it</a>
        </div>
    </div>
</section>
@else
<section class="container-km mt-12">
    @if ($x['title'] !== '' || $d['see_all'])
    <div class="mb-5 flex items-end {{ $x['title'] !== '' ? 'justify-between' : 'justify-end' }}">@if ($x['title'] !== '')<h2 class="text-3xl">{{ $x['title'] }}</h2>@endif @if ($d['see_all'])<a href="{{ url('/shop') }}" class="inline-flex min-h-12 items-center text-sm font-semibold text-brand-600">See all →</a>@endif</div>
    @endif
    @if ($d['layout'] === 'carousel')
    <div class="-mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0">
        @foreach ($x['products'] as $p)<div class="w-[46%] shrink-0 snap-start sm:w-[31%] md:w-[23%]">@include('store.partials.product-card', ['p' => $p])</div>@endforeach
    </div>
    @else
    <div class="grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-4">
        @foreach ($x['products'] as $p)@include('store.partials.product-card', ['p' => $p])@endforeach
    </div>
    @endif
</section>
@endif
