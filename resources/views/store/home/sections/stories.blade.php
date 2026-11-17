<section class="container-km mt-16">
    @if ($x['title'] !== '')<h2 class="text-3xl">{{ $x['title'] }}</h2>@endif
    <div class="mt-6 grid gap-6 {{ count($x['items']) === 1 ? '' : (count($x['items']) === 2 || count($x['items']) === 4 ? 'md:grid-cols-2' : 'md:grid-cols-3') }}">
        @foreach ($x['items'] as $story)
        <article class="{{ count($x['items']) === 1 && $story['image'] ? 'card grid items-center gap-6 md:grid-cols-2' : 'card' }}">
            @if ($story['image'])<div class="{{ count($x['items']) === 1 ? 'aspect-[4/3]' : 'mb-4 aspect-[4/3]' }} overflow-hidden rounded-xl">@include('store.partials.picture', ['image' => $story['image'], 'sizes' => count($x['items']) === 1 ? '(min-width: 768px) 50vw, 100vw' : '(min-width: 768px) 33vw, 100vw'])</div>@endif
            <div>
                @if ($story['name'] !== '')<h3 class="font-sans text-xl font-semibold">{{ $story['name'] }}</h3>@endif
                @if ($story['place'] !== '')<p class="text-sm font-semibold text-brand-600">{{ $story['place'] }}</p>@endif
                @if ($story['text'] !== '')<p class="muted mt-2 whitespace-pre-line">{{ $story['text'] }}</p>@endif
                @if ($story['button'])<a href="{{ $story['button']['href'] }}" class="mt-3 inline-flex min-h-12 items-center text-sm font-semibold text-brand-600">{{ $story['button']['text'] }} →</a>@endif
            </div>
        </article>
        @endforeach
    </div>
</section>
