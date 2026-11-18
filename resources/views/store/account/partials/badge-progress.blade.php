{{-- $b: one row from BadgeEngine::progress --}}
<div class="flex items-center gap-3" data-badge="{{ $b['key'] }}">
    <span class="grid size-11 shrink-0 place-items-center rounded-full {{ $b['unlocked'] ? 'text-white' : 'bg-mist-200 text-ink-700/50 dark:bg-white/10 dark:text-white/40' }}" @if ($b['unlocked']) style="background: {{ $b['color'] }}" @endif>
        {!! \App\Badges\BadgeIcons::svg($b['icon'], 22) !!}
    </span>
    <div class="min-w-0 flex-1">
        <div class="flex items-baseline justify-between gap-2"><b class="truncate">{{ $b['name'] }}</b>@if ($b['unlocked'])<span class="text-xs font-semibold" style="--badge: {{ $b['color'] }}" data-badge-text>✓</span>@endif</div>
        <div class="mt-1 h-2 overflow-hidden rounded-full bg-mist-200 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $b['percent'] }}" aria-label="{{ $b['name'] }} progress">
            <div class="h-full rounded-full" style="width: {{ max($b['percent'], $b['unlocked'] ? 100 : 2) }}%; background: {{ $b['color'] }}"></div>
        </div>
        <p class="muted mt-1 text-xs">{{ $b['label'] }}</p>
    </div>
</div>
