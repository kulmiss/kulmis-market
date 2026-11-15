{{-- label, value, sub?, icon, tone? (accent|neutral) --}}
<div class="m-card flex flex-col gap-2 p-4">
    <div class="flex items-center justify-between">
        <span class="text-xs font-medium uppercase tracking-wide m-muted">{{ $label }}</span>
        <span class="grid size-8 place-items-center rounded-full {{ ($tone ?? '') === 'accent' ? 'text-white' : 'm-soft m-accent-text' }}" @if (($tone ?? '') === 'accent') style="background: var(--m-accent)" @endif>{!! \App\Badges\BadgeIcons::svg($icon, 16) !!}</span>
    </div>
    <span class="text-xl font-bold tabular-nums leading-tight sm:text-2xl">{{ $value }}</span>
    @isset($sub)<span class="text-xs m-muted">{{ $sub }}</span>@endisset
</div>
