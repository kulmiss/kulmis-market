@php
    $site = \App\Support\Settings::string('site.name');
    $title = isset($title) ? $title.' · '.$site : $site.' — '.\App\Support\Settings::string('site.tagline');
    $description = $description ?? 'Handcrafted Ethiopian products, member prices, and trading signals.';
    $ogImage = $ogImage ?? asset('og-default.png');
    $topBar = \App\Models\Banner::query()->live('top_bar')->first();
@endphp
<!doctype html>
<html lang="en" class="h-full" @if (\App\Support\Features::disabled('site.instant_loading')) data-instant="off"@endif @if (\App\Support\Features::disabled('site.offline_page')) data-offline="off"@endif>
<head>
    <meta charset="utf-8">
    {{-- Also in a meta tag so static cached pages keep the policy (headers come from PHP for live pages). --}}
    <meta http-equiv="Content-Security-Policy" content="{{ str_replace("; frame-ancestors 'none'", '', \App\Support\Csp::storefront()) }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">
    <meta name="theme-color" content="#1f8a4c">
    <meta name="km-currency" content="{{ json_encode(\App\Support\Currency::forClients()) }}">
    <meta property="og:site_name" content="{{ $site }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    @feature('appearance.theme_switch')<script>{!! \App\Support\Csp::THEME_SCRIPT !!}</script>@endfeature
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @feature('site.instant_loading')<script type="speculationrules">{!! \App\Support\Csp::SPECULATION_RULES !!}</script>@endfeature
</head>
<body class="flex min-h-full flex-col">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:p-2">Skip to content</a>
@if ($topBar)
    {{-- Announcement bar. data-ends: the page may be a saved copy, so the browser hides it on time too. --}}
    <div class="{{ \App\Models\Banner::COLORS[$topBar->color ?? 'dark'] ?? \App\Models\Banner::COLORS['dark'] }} px-4 py-2 text-center text-sm" data-announce @if ($topBar->ends_at) data-ends="{{ $topBar->ends_at->toIso8601String() }}" @endif>
        {{ $topBar->title }} @if ($topBar->link_url)<a class="ms-2 underline" href="{{ $topBar->link_url }}">{{ $topBar->link_text ?? 'Learn more' }}</a>@endif
    </div>
@endif
@include('store.partials.header')
<main id="main" class="flex-1">
    @if (request()->has('signed_out') && auth()->guest())
        <div class="container-km mt-4"><div class="rounded-xl bg-leaf-600/10 px-4 py-3 text-leaf-600" role="status" data-signed-out>You are signed out.</div></div>
    @endif
    @if (session('status'))
        <div class="container-km mt-4"><div class="rounded-xl bg-leaf-600/10 px-4 py-3 text-leaf-600" role="status">{{ session('status') }}</div></div>
    @endif
    @yield('content')
</main>
@include('store.partials.footer')
@stack('scripts')
    @include('partials.ask')
</body>
</html>
