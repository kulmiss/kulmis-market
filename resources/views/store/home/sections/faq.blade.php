<section class="container-km mt-16"><div class="max-w-3xl">
    @if ($x['title'] !== '')<h2 class="text-3xl">{{ $x['title'] }}</h2>@endif
    <div class="mt-5 grid gap-3">
        @foreach ($x['items'] as $item)
        <details class="card py-2">
            <summary class="cursor-pointer font-semibold">{{ $item['q'] }}</summary>
            <p class="muted pb-3 whitespace-pre-line">{{ $item['a'] }}</p>
        </details>
        @endforeach
    </div>
</div></section>
