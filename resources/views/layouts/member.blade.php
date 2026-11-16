@php
    use App\Badges\BadgeIcons as I;
    $site = \App\Support\Settings::string('site.name');
    $accent = \App\Support\Settings::string('appearance.member_accent');
    $u = auth()->user();
    $nav = array_values(array_filter([
        ['Home', '/account', 'house', true],
        ['Team', '/account/team', 'users', \App\Support\Features::enabled('referral')],
        ['Earnings', '/account/earnings', 'trending-up', \App\Support\Features::enabled('referral')],
        ['Wallet', '/account/wallet', 'wallet', \App\Support\Features::enabled('wallet')],
        ['Badges', '/account/badges', 'award', \App\Support\Features::enabled('badges')],
        ['Share kit', '/account/share', 'share-2', \App\Support\Features::enabled('referral') && \App\Support\Features::enabled('referral.share_kit')],
        ['Contests', '/account/contests', 'trophy', \App\Support\Features::enabled('referral') && (\App\Support\Features::enabled('referral.contests') || \App\Support\Features::enabled('referral.challenges'))],
        ['Orders', '/account/orders', 'shopping-bag', \App\Support\Features::enabled('shop')],
        ['Signals', '/account/signals', 'radio', \App\Support\Features::enabled('signals')],
        ['Wishlist', '/account/wishlist', 'heart', \App\Support\Features::enabled('shop.wishlist')],
        ['Addresses', '/account/addresses', 'map-pin', \App\Support\Features::enabled('shop.saved_addresses')],
        ['Security', '/account/security', 'shield-check', true],
    ], fn ($n) => $n[3]));
    $bell = \App\Support\Features::enabled('account.notifications');
    $unread = $bell ? \App\Notify\Inbox::unread($u) : 0;
    $here = '/'.trim(request()->path(), '/');
    $isHere = fn ($href) => $href === '/account' ? $here === '/account' : str_starts_with($here, $href);
    $bottom = array_slice($nav, 0, 4);
@endphp
<!doctype html>
<html lang="en" class="h-full" style="--m-accent: {{ $accent }}" @if (\App\Support\Features::disabled('site.instant_loading')) data-instant="off"@endif @if (\App\Support\Features::disabled('site.offline_page')) data-offline="off"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ ($title ?? 'My account').' · '.$site }}</title>
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="{{ $accent }}">
    <meta name="km-currency" content="{{ json_encode(\App\Support\Currency::forClients()) }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset(\App\Support\Features::enabled('account.app') ? 'account.webmanifest' : 'site.webmanifest') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (\App\Support\Features::enabled('account.push') && \App\Notify\WebPush::configured())<meta name="km-push-key" content="{{ \App\Notify\WebPush::publicKey() }}">@endif
    <meta name="apple-mobile-web-app-capable" content="yes">
    @feature('appearance.theme_switch')<script>{!! \App\Support\Csp::THEME_SCRIPT !!}</script>@endfeature
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="km-member min-h-full font-sans antialiased">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:p-2">Skip to content</a>
<div class="lg:grid lg:min-h-screen lg:grid-cols-[248px_1fr]">
    {{-- Sidebar (desktop) --}}
    <aside class="hidden border-e lg:sticky lg:top-0 lg:flex lg:h-screen lg:flex-col lg:gap-1 lg:overflow-y-auto lg:p-4" style="border-color: var(--m-line); background: var(--m-surface)">
        <a href="{{ url('/') }}" class="mb-4 flex items-center gap-2 px-2 py-1 font-serif text-lg font-semibold">@include('partials.logo-mark', ['size' => 30]) {{ $site }}</a>
        <nav class="grid gap-1" aria-label="My account">
            @foreach ($nav as [$label, $href, $icon])
                <a href="{{ url($href) }}" class="m-nav-link" @if ($isHere($href)) aria-current="page" @endif>{!! I::svg($icon, 19) !!}{{ $label }}</a>
            @endforeach
            @if ($bell)
                <a href="{{ url('/account/notifications') }}" class="m-nav-link" @if ($isHere('/account/notifications')) aria-current="page" @endif>{!! I::svg('bell', 19) !!}Notifications
                    @if ($unread)<span class="ms-auto rounded-full px-2 text-xs font-bold text-white" style="background: var(--m-accent)">{{ $unread > 99 ? '99+' : $unread }}<span class="sr-only"> new</span></span>@endif
                </a>
            @endif
        </nav>
        <div class="mt-auto grid gap-1 border-t pt-3" style="border-color: var(--m-line)">
            <a href="{{ url('/shop') }}" class="m-nav-link">{!! I::svg('store', 19) !!}Shop</a>
            @feature('appearance.theme_switch')<button type="button" data-theme-toggle class="m-nav-link text-start">{!! I::svg('moon', 19) !!}Light / dark</button>@endfeature
            <form method="post" action="{{ url('/logout') }}" data-confirm="Do you want to sign out?" data-confirm-ok="Sign out">@csrf<button class="m-nav-link w-full text-start">{!! I::svg('log-out', 19) !!}Sign out</button></form>
        </div>
    </aside>

    <div class="flex min-h-screen flex-col">
        {{-- Top bar (mobile) --}}
        <header class="sticky top-0 z-30 flex h-14 items-center gap-2 border-b px-4 backdrop-blur lg:hidden" style="border-color: var(--m-line); background: color-mix(in srgb, var(--m-surface) 88%, transparent)">
            <a href="{{ url('/') }}" class="-ms-2 grid size-12 place-items-center" aria-label="{{ $site }} home">@include('partials.logo-mark', ['size' => 28])</a>
            <span class="font-semibold">{{ $title ?? 'My account' }}</span>
            <span class="ms-auto"></span>
            @if ($bell)
                <a href="{{ url('/account/notifications') }}" class="relative grid size-12 place-items-center rounded-full" aria-label="Notifications{{ $unread ? " ($unread new)" : '' }}">{!! I::svg('bell', 20) !!}
                    @if ($unread)<span class="absolute end-2 top-2 grid min-w-5 place-items-center rounded-full px-1 text-[10px] font-bold leading-5 text-white" style="background: var(--m-accent)" aria-hidden="true">{{ $unread > 9 ? '9+' : $unread }}</span>@endif
                </a>
            @endif
            @feature('appearance.theme_switch')<button type="button" data-theme-toggle class="grid size-12 place-items-center rounded-full" aria-label="Switch light/dark">{!! I::svg('moon', 20) !!}</button>@endfeature
            <a href="{{ url('/account/security') }}" class="-me-2 grid size-12 place-items-center" aria-label="My profile and security"><span class="grid size-8 place-items-center rounded-full text-sm font-bold text-white" style="background: var(--m-accent)">{{ mb_substr($u->first_name, 0, 1) }}</span></a>
        </header>

        <main id="main" class="mx-auto w-full max-w-6xl flex-1 px-4 pb-28 pt-5 sm:px-6 lg:pb-10 lg:pt-8">
            @if (session('status'))<div class="mb-4 rounded-xl px-4 py-3 text-sm m-soft m-accent-text" role="status">{{ session('status') }}</div>@endif
            @if ($errors->any())<div class="mb-4 rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-300" role="alert">{{ $errors->first() }}</div>@endif
            @yield('content')
        </main>
    </div>
</div>

{{-- Bottom nav (mobile) --}}
<nav class="m-bottom fixed inset-x-0 bottom-0 z-40 grid border-t pb-[env(safe-area-inset-bottom)] lg:hidden" style="grid-template-columns: repeat({{ count($bottom) + 1 }}, minmax(0, 1fr)); border-color: var(--m-line); background: var(--m-surface)" aria-label="My account (mobile)">
    @foreach ($bottom as [$label, $href, $icon])
        <a href="{{ url($href) }}" class="flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium m-muted" @if ($isHere($href)) aria-current="page" @endif>{!! I::svg($icon, 22) !!}{{ $label }}</a>
    @endforeach
    <details class="relative">
        <summary class="flex list-none flex-col items-center gap-0.5 py-2 text-[11px] font-medium m-muted">{!! I::svg('list', 22) !!}More</summary>
        <div class="m-card absolute bottom-16 end-2 grid w-56 gap-1 p-2">
            @foreach (array_slice($nav, 4) as [$label, $href, $icon])
                <a href="{{ url($href) }}" class="m-nav-link">{!! I::svg($icon, 18) !!}{{ $label }}</a>
            @endforeach
            <a href="{{ url('/shop') }}" class="m-nav-link">{!! I::svg('store', 18) !!}Shop</a>
            <form method="post" action="{{ url('/logout') }}" data-confirm="Do you want to sign out?" data-confirm-ok="Sign out">@csrf<button class="m-nav-link w-full text-start">{!! I::svg('log-out', 18) !!}Sign out</button></form>
        </div>
    </details>
</nav>
@stack('scripts')
    @include('partials.ask')
</body>
</html>
