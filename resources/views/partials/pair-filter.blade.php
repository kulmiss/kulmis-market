{{-- Pair chips: "All" + every pair with results. $pairs, $pair (current), $base (url) --}}
@if (count($pairs) > 1)
<nav class="flex flex-wrap gap-2" aria-label="Filter by pair">
    <a href="{{ url($base) }}" @class(['inline-flex min-h-10 items-center rounded-full border px-3 text-sm', 'font-semibold border-current' => $pair === null]) @if ($pair === null) aria-current="true" @endif>All pairs</a>
    @foreach ($pairs as $p)
        <a href="{{ url($base).'?pair='.urlencode($p) }}" @class(['inline-flex min-h-10 items-center rounded-full border px-3 text-sm', 'font-semibold border-current' => $pair === $p]) @if ($pair === $p) aria-current="true" @endif>{{ $p }}</a>
    @endforeach
</nav>
@endif
