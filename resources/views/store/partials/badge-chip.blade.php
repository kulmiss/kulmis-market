{{-- $b: ['name','icon','color'], optional $size --}}
<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" style="background: {{ $b['color'] }}1f; --badge: {{ $b['color'] }}" data-badge-text>
    {!! \App\Badges\BadgeIcons::svg($b['icon'], $size ?? 14) !!}<span>{{ $b['name'] }}</span>
</span>
