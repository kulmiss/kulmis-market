@php
    $a = $x['accent'];
    $behind = $d['layout'] === 'behind' && $x['image'];
    $center = $d['align'] === 'center';
    $light = $a['onDark'] || $behind;
    $sizes = $behind ? '100vw' : '(min-width: 768px) 50vw, 100vw';
@endphp
<section class="{{ trim(($behind ? 'relative overflow-hidden bg-ink-900 text-white' : $a['class'])) }}" @if (! $behind && $a['style']) style="{{ $a['style'] }}" @endif>
    @if ($behind)
        <div class="absolute inset-0" aria-hidden="true">@include('store.partials.picture', ['image' => $x['image'], 'eager' => $x['eager'], 'sizes' => $sizes, 'class' => 'h-full w-full object-cover opacity-45'])</div>
    @endif
    <div class="{{ $behind ? 'relative container-km py-20 md:py-28' : 'container-km grid items-center gap-10 py-14 '.($center ? '' : 'md:grid-cols-2 ').'md:py-20' }}">
        <div @if ($center) class="text-center" @elseif ($behind) class="max-w-2xl" @endif>
            @if ($x['label'] !== '')<p class="{{ $light ? 'badge mb-4 bg-white/15 text-white dark:bg-white/10' : 'badge mb-4' }}">{{ $x['label'] }}</p>@endif
            <{{ $x['level'] }} class="text-4xl leading-tight font-semibold md:text-5xl">{{ $x['headline'] }}</{{ $x['level'] }}>
            @if ($x['subline'] !== '')<p class="{{ $light ? 'mt-4 max-w-md text-lg text-white/85' : 'muted mt-4 max-w-md text-lg' }}{{ $center ? ' mx-auto' : '' }}">{{ $x['subline'] }}</p>@endif
            @if ($x['buttons'] !== [])
            <div class="mt-7 flex flex-wrap gap-3{{ $center ? ' justify-center' : '' }}">
                @foreach ($x['buttons'] as $i => $btn)<a href="{{ $btn['href'] }}" class="{{ $light ? ($i === 1 ? 'btn bg-white text-brand-700 hover:bg-mist-100' : 'btn border border-white/60 text-white hover:bg-white/10') : ($i === 1 ? 'btn-primary' : 'btn-ghost') }}">{{ $btn['text'] }}</a>
                @endforeach
            </div>
            @endif
        </div>
        @if (! $behind && $x['image'])
            @if ($x['product'])
            <a href="{{ url('/shop/'.$x['product']->slug) }}" class="block aspect-[4/5] overflow-hidden rounded-[2rem] shadow-xl{{ $center ? ' mx-auto w-full max-w-sm' : ($d['layout'] === 'left' ? ' md:order-first' : '') }}">
                @include('store.partials.picture', ['image' => $x['image'], 'eager' => $x['eager'], 'sizes' => $sizes])
            </a>
            @else
            <div class="aspect-[4/5] overflow-hidden rounded-[2rem] shadow-xl{{ $center ? ' mx-auto w-full max-w-sm' : ($d['layout'] === 'left' ? ' md:order-first' : '') }}">
                @include('store.partials.picture', ['image' => $x['image'], 'eager' => $x['eager'], 'sizes' => $sizes])
            </div>
            @endif
        @endif
    </div>
</section>
