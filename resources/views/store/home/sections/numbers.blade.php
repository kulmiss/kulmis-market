{{-- Counted from the real data (App\Home\HomeNumbers): never typed in. --}}
<section class="container-km mt-16">
    @if ($x['title'] !== '')<h2 class="mb-5 text-3xl">{{ $x['title'] }}</h2>@endif
    <dl class="grid grid-cols-2 gap-4 {{ count($x['numbers']) >= 4 ? 'md:grid-cols-4' : (count($x['numbers']) === 3 ? 'md:grid-cols-3' : '') }}">
        @foreach ($x['numbers'] as $n)
        <div class="card flex flex-col"><dt class="muted order-last">{{ $n['label'] }}</dt><dd class="text-3xl font-semibold text-brand-600 tabular-nums">{{ $n['value'] }}</dd></div>
        @endforeach
    </dl>
</section>
