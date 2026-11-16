@php
    // Error pages must work even when the database does not: then show the shop links.
    $on = function (string $key): bool {
        try {
            return \App\Support\Features::enabled($key);
        } catch (\Throwable) {
            return true;
        }
    };
@endphp
{{-- Error pages must work even when the database is down: no settings, no session. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite('resources/css/app.css')
</head>
<body class="min-h-svh bg-mist-50 text-ink-900 dark:bg-ink-950 dark:text-mist-50">
<main class="container-km grid min-h-svh max-w-xl place-content-center py-16 text-center">
    <a href="/" class="mx-auto mb-8 inline-flex items-center gap-2 font-serif text-xl" aria-label="{{ config('app.name') }} home">
        @include('partials.logo-mark', ['size' => 32])
        {{ config('app.name') }}
    </a>
    <p class="text-sm font-semibold text-brand-600">@yield('code')</p>
    <h1 class="mt-2 text-4xl">@yield('title')</h1>
    <p class="muted mt-3">@yield('message')</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3">
        @hasSection('action')@yield('action')@else<a class="btn-primary" href="/">{{ $on('shop') ? 'Go to the shop' : 'Go to the home page' }}</a>@endif
        @if ($on('shop') && $on('shop.order_tracking'))<a class="btn-ghost" href="/track">Track an order</a>@endif
    </div>
</main>
</body>
</html>
