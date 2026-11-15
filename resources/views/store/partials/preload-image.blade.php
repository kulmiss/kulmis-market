{{-- Start downloading the main photo right away, before the CSS is read (faster LCP). --}}
@php($f = $image ? collect(['avif', 'webp'])->first(fn ($x) => in_array($x, $image->formats, true)) : null)
@if ($f)
<link rel="preload" as="image" type="image/{{ $f }}" imagesrcset="{{ $image->srcsets()[$f] }}" imagesizes="{{ $sizes }}" fetchpriority="high">
@endif
