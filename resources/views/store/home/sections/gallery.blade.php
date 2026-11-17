<section class="container-km mt-16">
    @if ($x['title'] !== '')<h2 class="mb-5 text-3xl">{{ $x['title'] }}</h2>@endif
    <div class="{{ $d['layout'] === 'row' ? '-mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0' : 'grid grid-cols-2 gap-4 md:grid-cols-3' }}">
        @foreach ($x['items'] as $pic)
        <figure class="{{ $d['layout'] === 'row' ? 'w-[70%] shrink-0 snap-start sm:w-[40%] md:w-[30%]' : '' }}">
            <div class="aspect-square overflow-hidden rounded-2xl">@include('store.partials.picture', ['image' => $pic['image'], 'sizes' => '(min-width: 768px) 33vw, 50vw'])</div>
            @if ($pic['caption'] !== '')<figcaption class="muted mt-2 text-sm">{{ $pic['caption'] }}</figcaption>@endif
        </figure>
        @endforeach
    </div>
</section>
