<section class="container-km mt-16">
    <div class="card text-center md:p-8">
        @if ($x['title'] !== '')<h2 class="text-3xl">{{ $x['title'] }}</h2>@endif
        @if ($x['text'] !== '')<p class="muted mx-auto mt-2 max-w-xl whitespace-pre-line">{{ $x['text'] }}</p>@endif
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            @foreach ($x['buttons'] as $i => $btn)<a href="{{ $btn['href'] }}" class="{{ $i === 0 ? 'btn-primary' : 'btn-ghost' }}" @if (str_starts_with($btn['href'], 'https://')) rel="noopener" target="_blank" @endif>{{ $btn['text'] }}</a>@endforeach
        </div>
    </div>
</section>
