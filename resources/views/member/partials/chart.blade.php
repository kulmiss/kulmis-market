{{-- $points: list of [label, value]; bars in the accent colour, drawn as SVG (no JS) --}}
@php
    $max = max(1, max(array_column($points, 'value')));
    $n = count($points);
    $w = 600; $h = 160; $gap = $n > 20 ? 2 : 6;
    $bw = ($w - $gap * ($n - 1)) / max(1, $n);
    $fmt = fn ($v) => \App\Support\Money::format((int) $v);
@endphp
<svg viewBox="0 0 {{ $w }} {{ $h + 22 }}" class="h-44 w-full" role="img" aria-label="Earnings chart">
    <line x1="0" x2="{{ $w }}" y1="{{ $h }}" y2="{{ $h }}" stroke="currentColor" stroke-opacity=".12"/>
    @foreach ($points as $i => $p)
        @php $bh = $p['value'] > 0 ? max(3, $p['value'] / $max * ($h - 8)) : 2; $x = $i * ($bw + $gap); @endphp
        <rect x="{{ round($x, 1) }}" y="{{ round($h - $bh, 1) }}" width="{{ round($bw, 1) }}" height="{{ round($bh, 1) }}" rx="{{ min(4, $bw / 3) }}" class="{{ $p['value'] > 0 ? 'm-bar' : 'm-bar-muted' }}" style="fill: var(--m-accent); {{ $p['value'] > 0 ? '' : 'opacity:.18' }}"><title>{{ $p['label'] }}: {{ $fmt($p['value']) }}</title></rect>
        @if ($n <= 12 || $i % 5 === 0 || $i === $n - 1)
            <text x="{{ round($x + $bw / 2, 1) }}" y="{{ $h + 16 }}" text-anchor="middle" font-size="11" fill="currentColor" fill-opacity=".55">{{ $p['label'] }}</text>
        @endif
    @endforeach
</svg>
