{{-- Lazy video: nothing from YouTube/Vimeo loads until the play button is tapped (resources/js/chunks/video.js). --}}
@php($ratio = $x['file'] && $x['file']->width > 0 && $x['file']->height > 0 ? $x['file']->width.' / '.$x['file']->height : '16 / 9')
<section class="container-km mt-16">
    @if ($x['title'] !== '')<h2 class="mb-5 text-3xl">{{ $x['title'] }}</h2>@endif
    <figure>
        @if ($x['file'])
        <video class="w-full rounded-2xl bg-ink-900" style="aspect-ratio: {{ $ratio }}" controls preload="none" playsinline
            @if ($x['poster']) poster="{{ $x['poster']->url(1600, in_array('jpg', $x['poster']->formats, true) ? 'jpg' : 'webp') }}" @endif
            width="{{ $x['file']->width ?: 1280 }}" height="{{ $x['file']->height ?: 720 }}" @if ($x['file']->alt) aria-label="{{ $x['file']->alt }}" @endif>
            <source src="{{ $x['file']->url() }}" type="video/mp4">
        </video>
        @else
        <div class="relative aspect-video overflow-hidden rounded-2xl bg-ink-900" data-video="{{ $x['link']['embed'] }}" data-video-title="{{ $x['title'] !== '' ? $x['title'] : 'Video' }}">
            @if ($x['poster'])@include('store.partials.picture', ['image' => $x['poster'], 'sizes' => '(min-width: 1152px) 1152px, 100vw', 'class' => 'h-full w-full object-cover opacity-90'])@endif
            <button type="button" class="absolute inset-0 flex items-center justify-center text-white" data-video-play aria-label="Play video{{ $x['title'] !== '' ? ': '.$x['title'] : '' }}">
                <span class="flex h-20 w-20 items-center justify-center rounded-full bg-ink-900/70 ring-2 ring-white/80"><svg width="34" height="34" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span>
            </button>
        </div>
        @endif
        @if ($x['caption'] !== '' || $x['link'])
        <figcaption class="muted mt-2 text-sm">{{ $x['caption'] }}@if ($x['link'])@if ($x['caption'] !== '') · @endif<a href="{{ $x['link']['watch'] }}" rel="noopener" target="_blank" class="underline">Watch on {{ $x['link']['site'] }}</a>@endif</figcaption>
        @endif
    </figure>
</section>
