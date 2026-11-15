{{-- Error pages must work even when the database is down: no settings, no session. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite('resources/css/app.css')
</head>
<body class="min-h-svh bg-sand-50 text-coffee-900 dark:bg-coffee-950 dark:text-sand-50">
<main class="container-km grid min-h-svh max-w-xl place-content-center py-16 text-center">
    <a href="/" class="mx-auto mb-8 inline-flex items-center gap-2 font-serif text-xl" aria-label="{{ config('app.name') }} home">
        <svg width="32" height="32" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="#b5532b"/><path d="M8 20c4-8 12-8 16 0M10 14c3-4 9-4 12 0" stroke="#fbf8f3" stroke-width="2" fill="none" stroke-linecap="round"/></svg>
        {{ config('app.name') }}
    </a>
    <p class="text-sm font-semibold text-clay-600">@yield('code')</p>
    <h1 class="mt-2 text-4xl">@yield('title')</h1>
    <p class="muted mt-3">@yield('message')</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3">
        @hasSection('action')@yield('action')@else<a class="btn-primary" href="/">Go to the shop</a>@endif
        <a class="btn-ghost" href="/track">Track an order</a>
    </div>
</main>
</body>
</html>
