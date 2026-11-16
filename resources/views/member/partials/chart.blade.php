{{-- $points: list of [label, value]; bars in the accent colour, drawn as SVG (no JS).
     Two drawings: a narrow one for phones (readable labels, fewer of them) and a wide one. --}}
@php
    $max = max(1, max(array_column($points, 'value')));
    $n = count($points);
    $fmt = fn ($v) => \App\Support\Money::format((int) $v);
    $total = array_sum(array_column($points, 'value'));
@endphp
@foreach ([['w' => 320, 'every' => $n <= 12 ? 3 : 10, 'class' => 'h-48 w-full sm:hidden'], ['w' => 600, 'every' => $n <= 12 ? 1 : 5, 'class' => 'hidden h-44 w-full sm:block']] as $v)
    @php
        $w = $v['w']; $h = 160; $gap = $n > 20 ? ($w < 400 ? 1.5 : 2) : 6;
        $bw = ($w - $gap * ($n - 1)) / max(1, $n);
    @endphp
    <svg viewBox="0 0 {{ $w }} {{ $h + 24 }}" class="{{ $v['class'] }}" role="img" aria-label="Earnings chart: {{ $fmt($total) }} in this period">
        <line x1="0" x2="{{ $w }}" y1="{{ $h }}" y2="{{ $h }}" stroke="currentColor" stroke-opacity=".12"/>
        @foreach ($points as $i => $p)
            @php $bh = $p['value'] > 0 ? max(3, $p['value'] / $max * ($h - 8)) : 2; $x = $i * ($bw + $gap); @endphp
            <rect x="{{ round($x, 1) }}" y="{{ round($h - $bh, 1) }}" width="{{ round($bw, 1) }}" height="{{ round($bh, 1) }}" rx="{{ min(4, $bw / 3) }}" class="{{ $p['value'] > 0 ? 'm-bar' : 'm-bar-muted' }}" style="fill: var(--m-accent); {{ $p['value'] > 0 ? '' : 'opacity:.18' }}"><title>{{ $p['label'] }}: {{ $fmt($p['value']) }}</title></rect>
            @if ($i % $v['every'] === 0 || $i === $n - 1)
                @php $anchor = $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle'); $tx = $i === 0 ? $x : ($i === $n - 1 ? $x + $bw : $x + $bw / 2); @endphp
                @if ($i === $n - 1 || $n - 1 - $i >= $v['every'] / 2)
                    <text x="{{ round($tx, 1) }}" y="{{ $h + 17 }}" text-anchor="{{ $anchor }}" font-size="12" fill="currentColor" fill-opacity=".6">{{ $p['label'] }}</text>
                @endif
            @endif
        @endforeach
    </svg>
@endforeach
