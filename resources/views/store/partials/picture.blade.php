{{-- Responsive image: AVIF → WebP → JPEG. --}}
@php($img = $image ?? null)
@if ($img)
    <picture>
        @foreach (['avif', 'webp'] as $f)
            @if (in_array($f, $img->formats, true))<source type="image/{{ $f }}" srcset="{{ $img->srcsets()[$f] }}" sizes="{{ $sizes ?? '(min-width: 1024px) 25vw, 50vw' }}">@endif
        @endforeach
        <img src="{{ $img->url(in_array(800, $img->widths, true) ? 800 : max($img->widths), in_array('jpg', $img->formats, true) ? 'jpg' : $img->formats[0]) }}"
             width="{{ $img->width }}" height="{{ $img->height }}" alt="{{ $img->alt ?? '' }}"
             class="{{ $class ?? 'h-full w-full object-cover' }}"
             @if (!empty($eager)) fetchpriority="high" decoding="async" @else loading="lazy" decoding="async" @endif>
    </picture>
@else
    <div class="flex h-full w-full items-center justify-center bg-sand-200 text-sand-300 dark:bg-coffee-800" aria-hidden="true">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 16l4-4 4 4 4-6 4 6"/><rect x="3" y="4" width="18" height="16" rx="2"/></svg>
    </div>
@endif
