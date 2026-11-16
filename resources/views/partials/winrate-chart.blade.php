{{-- Win rate by month: plain SVG bars (no script), readable on a 360 px phone. $months from SignalStats::monthly() --}}
@php $w = 36; $gap = 8; $h = 120; $total = count($months) * ($w + $gap); @endphp
<figure class="m-0">
    <svg viewBox="0 0 {{ $total }} {{ $h + 34 }}" class="h-auto w-full" role="img" aria-labelledby="wr-title wr-desc">
        <title id="wr-title">Win rate by month</title>
        <desc id="wr-desc">@foreach ($months as $m){{ $m['label'] }}: {{ $m['rate'] === null ? 'no closed signals' : $m['rate'].'% ('.$m['wins'].' wins, '.$m['losses'].' losses)' }}. @endforeach</desc>
        <line x1="0" x2="{{ $total }}" y1="{{ $h * 0.5 }}" y2="{{ $h * 0.5 }}" stroke="currentColor" stroke-opacity=".15" stroke-dasharray="3 3"/>
        @foreach ($months as $i => $m)
            @php $x = $i * ($w + $gap) + $gap / 2; $bh = $m['rate'] === null ? 0 : max(2, $h * $m['rate'] / 100); @endphp
            @if ($m['rate'] !== null)
                <rect x="{{ $x }}" y="{{ $h - $bh }}" width="{{ $w }}" height="{{ $bh }}" rx="5" fill="currentColor" class="text-brand-600"/>
                <text x="{{ $x + $w / 2 }}" y="{{ max(11, $h - $bh - 4) }}" text-anchor="middle" font-size="11" font-weight="600" fill="currentColor" @if ($bh > 100) class="text-white" @endif>{{ $m['rate'] }}%</text>
            @else
                <rect x="{{ $x }}" y="{{ $h - 2 }}" width="{{ $w }}" height="2" rx="1" fill="currentColor" opacity=".2"/>
            @endif
            <text x="{{ $x + $w / 2 }}" y="{{ $h + 16 }}" text-anchor="middle" font-size="11" fill="currentColor" opacity=".7">{{ $m['label'] }}</text>
            <text x="{{ $x + $w / 2 }}" y="{{ $h + 30 }}" text-anchor="middle" font-size="10" fill="currentColor" opacity=".5">{{ $m['wins'] + $m['losses'] ?: '' }}</text>
        @endforeach
    </svg>
    <figcaption class="mt-1 text-xs opacity-70">Bars: wins ÷ (wins + losses) each month. Small number: signals closed that month. Dashed line: 50%.</figcaption>
</figure>
