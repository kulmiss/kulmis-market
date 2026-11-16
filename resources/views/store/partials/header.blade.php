<header class="sticky top-0 z-30 border-b border-mist-200 bg-mist-50/90 backdrop-blur dark:border-ink-800 dark:bg-ink-900/90">
    <div class="container-km flex h-16 items-center gap-2 sm:gap-4">
        <a href="{{ url('/') }}" class="flex min-w-0 items-center gap-2 font-serif text-base font-semibold sm:text-xl" aria-label="{{ \App\Support\Settings::string('site.name') }} home">
            @include('partials.logo-mark', ['size' => 28])
            <span class="truncate whitespace-nowrap" data-brand-name>{{ \App\Support\Settings::string('site.name') }}</span>
        </a>
        <nav class="ms-4 hidden items-center gap-5 text-sm font-medium md:flex" aria-label="Main">
            @feature('shop')<a href="{{ url('/shop') }}" class="hover:text-brand-600">Shop</a>@endfeature
            @feature('membership')<a href="{{ url('/membership') }}" class="hover:text-brand-600">Membership</a>@endfeature
            @feature('signals')<a href="{{ url('/signals') }}" class="hover:text-brand-600">Signals</a>@endfeature
            @feature('referral')<a href="{{ url('/referral-program') }}" class="hover:text-brand-600">Earn</a>@endfeature
            @feature('shop.order_tracking')<a href="{{ url('/track') }}" class="hover:text-brand-600">Track order</a>@endfeature
        </nav>
        @php($me = auth()->user())
        <div class="ms-auto flex items-center gap-1">
            <details class="relative md:hidden">
                <summary class="grid size-12 list-none place-items-center rounded-full hover:bg-mist-100 sm:size-10 dark:hover:bg-ink-800" aria-label="Menu">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </summary>
                <nav class="absolute end-0 mt-2 grid w-52 gap-1 rounded-2xl border border-mist-200 bg-mist-50 p-2 text-sm shadow-lg dark:border-ink-700 dark:bg-ink-900" aria-label="Mobile">
                    @feature('shop')<a class="rounded-lg px-3 py-2 hover:bg-mist-100 dark:hover:bg-ink-800" href="{{ url('/shop') }}">Shop</a>@endfeature
                    @feature('membership')<a class="rounded-lg px-3 py-2 hover:bg-mist-100 dark:hover:bg-ink-800" href="{{ url('/membership') }}">Membership</a>@endfeature
                    @feature('signals')<a class="rounded-lg px-3 py-2 hover:bg-mist-100 dark:hover:bg-ink-800" href="{{ url('/signals') }}">Signals</a>@endfeature
                    @feature('referral')<a class="rounded-lg px-3 py-2 hover:bg-mist-100 dark:hover:bg-ink-800" href="{{ url('/referral-program') }}">Earn with referrals</a>@endfeature
                    @feature('shop.order_tracking')<a class="rounded-lg px-3 py-2 hover:bg-mist-100 dark:hover:bg-ink-800" href="{{ url('/track') }}">Track order</a>@endfeature
                </nav>
            </details>
            @feature('appearance.theme_switch')
            <button type="button" data-theme-toggle class="grid size-12 place-items-center rounded-full hover:bg-mist-100 sm:size-10 dark:hover:bg-ink-800" aria-label="Switch light/dark">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </button>
            @endfeature
            @feature('shop')
            <a href="{{ url('/cart') }}" class="relative grid size-12 place-items-center rounded-full hover:bg-mist-100 sm:size-10 dark:hover:bg-ink-800" aria-label="Cart">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 7h14l-1.5 12h-11z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>
                <span data-cart-count class="absolute -end-0.5 -top-0.5 hidden min-w-5 rounded-full bg-brand-600 px-1 text-center text-[11px] font-bold leading-5 text-white"></span>
            </a>
            @endfeature
            @if ($me)
                @php($first = trim((string) $me->first_name) !== '' ? $me->first_name : 'Account')
                <a href="{{ url('/account') }}" data-account class="flex min-h-12 min-w-12 items-center justify-center gap-2 rounded-full py-1 ps-1 pe-1 hover:bg-mist-100 sm:min-h-10 sm:min-w-0 sm:pe-3 dark:hover:bg-ink-800" aria-label="My account ({{ $first }})">
                    <span class="flex size-8 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr($first, 0, 1)) }}</span>
                    <span class="hidden max-w-32 truncate text-sm font-medium sm:inline">{{ $first }}</span>
                </a>
            @else
                <a href="{{ url('/login') }}" data-account class="grid size-12 place-items-center rounded-full hover:bg-mist-100 sm:size-10 dark:hover:bg-ink-800" aria-label="Sign in">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
                </a>
            @endif
        </div>
    </div>
</header>
