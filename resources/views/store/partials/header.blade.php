<header class="sticky top-0 z-30 border-b border-sand-200 bg-sand-50/90 backdrop-blur dark:border-coffee-800 dark:bg-coffee-900/90">
    <div class="container-km flex h-16 items-center gap-4">
        <a href="{{ url('/') }}" class="flex items-center gap-2 font-serif text-xl font-semibold" aria-label="{{ \App\Support\Settings::string('site.name') }} home">
            <svg width="28" height="28" viewBox="0 0 32 32" aria-hidden="true"><circle cx="16" cy="16" r="15" fill="#b5532b"/><path d="M8 20c4-8 12-8 16 0M10 14c3-4 9-4 12 0" stroke="#fbf8f3" stroke-width="2" fill="none" stroke-linecap="round"/></svg>
            <span>{{ \App\Support\Settings::string('site.name') }}</span>
        </a>
        <nav class="ms-4 hidden items-center gap-5 text-sm font-medium md:flex" aria-label="Main">
            @feature('shop')<a href="{{ url('/shop') }}" class="hover:text-clay-600">Shop</a>@endfeature
            @feature('membership')<a href="{{ url('/membership') }}" class="hover:text-clay-600">Membership</a>@endfeature
            @feature('signals')<a href="{{ url('/signals') }}" class="hover:text-clay-600">Signals</a>@endfeature
            @feature('referral')<a href="{{ url('/referral-program') }}" class="hover:text-clay-600">Earn</a>@endfeature
            <a href="{{ url('/track') }}" class="hover:text-clay-600">Track order</a>
        </nav>
        <div class="ms-auto flex items-center gap-1">
            <button type="button" data-theme-toggle class="rounded-full p-2 hover:bg-sand-100 dark:hover:bg-coffee-800" aria-label="Switch light/dark">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </button>
            <a href="{{ url('/account') }}" class="rounded-full p-2 hover:bg-sand-100 dark:hover:bg-coffee-800" aria-label="My account">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
            </a>
            @feature('shop')
            <a href="{{ url('/cart') }}" class="relative rounded-full p-2 hover:bg-sand-100 dark:hover:bg-coffee-800" aria-label="Cart">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 7h14l-1.5 12h-11z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>
                <span data-cart-count class="absolute -end-0.5 -top-0.5 hidden min-w-5 rounded-full bg-clay-500 px-1 text-center text-[11px] font-bold leading-5 text-white"></span>
            </a>
            @endfeature
            <details class="relative md:hidden">
                <summary class="list-none rounded-full p-2 hover:bg-sand-100 dark:hover:bg-coffee-800" aria-label="Menu">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </summary>
                <nav class="absolute end-0 mt-2 grid w-52 gap-1 rounded-2xl border border-sand-200 bg-sand-50 p-2 text-sm shadow-lg dark:border-coffee-700 dark:bg-coffee-900" aria-label="Mobile">
                    @feature('shop')<a class="rounded-lg px-3 py-2 hover:bg-sand-100 dark:hover:bg-coffee-800" href="{{ url('/shop') }}">Shop</a>@endfeature
                    @feature('membership')<a class="rounded-lg px-3 py-2 hover:bg-sand-100 dark:hover:bg-coffee-800" href="{{ url('/membership') }}">Membership</a>@endfeature
                    @feature('signals')<a class="rounded-lg px-3 py-2 hover:bg-sand-100 dark:hover:bg-coffee-800" href="{{ url('/signals') }}">Signals</a>@endfeature
                    @feature('referral')<a class="rounded-lg px-3 py-2 hover:bg-sand-100 dark:hover:bg-coffee-800" href="{{ url('/referral-program') }}">Earn with referrals</a>@endfeature
                    <a class="rounded-lg px-3 py-2 hover:bg-sand-100 dark:hover:bg-coffee-800" href="{{ url('/track') }}">Track order</a>
                </nav>
            </details>
        </div>
    </div>
</header>
