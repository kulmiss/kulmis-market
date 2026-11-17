<section class="container-km mt-12">
    @if ($x['title'] !== '')<h2 class="mb-4 text-2xl">{{ $x['title'] }}</h2>@endif
    @if ($d['style'] === 'cards')
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
        @foreach ($x['categories'] as $c)<a href="{{ url('/c/'.$c->slug) }}" class="card flex min-h-20 items-center justify-between gap-2 font-semibold hover:border-brand-500">{{ $c->name }}<span aria-hidden="true" class="text-brand-600">→</span></a>@endforeach
    </div>
    @else
    <div class="flex flex-wrap gap-2">
        @foreach ($x['categories'] as $c)<a href="{{ url('/c/'.$c->slug) }}" class="btn-ghost">{{ $c->name }}</a>@endforeach
    </div>
    @endif
</section>
