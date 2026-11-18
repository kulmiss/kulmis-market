@foreach ($x['banners'] as $b)
    <div class="container-km mt-8"><div class="card flex flex-wrap items-center justify-between gap-3">
        <div><p class="font-serif text-xl">{{ $b->title }}</p>@if ($b->text)<p class="muted">{{ $b->text }}</p>@endif</div>
        @if ($b->link_url)<a href="{{ $b->link_url }}" class="btn-dark">{{ $b->link_text ?? 'See more' }}</a>@endif
    </div></div>
@endforeach
