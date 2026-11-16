@php($pages = \App\Models\Page::query()->where('is_published', true)->where('in_footer', true)->orderBy('position')->get(['slug', 'title']))
<footer class="mt-16 border-t border-mist-200 bg-mist-100/60 py-10 text-sm dark:border-ink-800 dark:bg-ink-900">
    <div class="container-km grid gap-8 sm:grid-cols-3">
        <div>
            <p class="font-serif text-lg font-semibold">{{ \App\Support\Settings::string('site.name') }}</p>
            <p class="muted mt-1">{{ \App\Support\Settings::string('site.tagline') }}</p>
            <p class="muted mt-3">{{ \App\Support\Settings::string('site.support_phone') }}<br>{{ \App\Support\Settings::string('site.support_email') }}</p>
        </div>
        <nav class="grid content-start gap-2" aria-label="Shop links">
            @feature('shop')<a href="{{ url('/shop') }}" class="hover:underline">Shop</a>@endfeature
            @feature('membership')<a href="{{ url('/membership') }}" class="hover:underline">Membership</a>@endfeature
            @feature('signals')<a href="{{ url('/signals') }}" class="hover:underline">Trading signals</a>@endfeature
            @feature('shop.order_tracking')<a href="{{ url('/track') }}" class="hover:underline">Track your order</a>@endfeature
            @feature('shop.custom_orders')<a href="{{ url('/custom-order') }}" class="hover:underline">Custom order</a>@endfeature
            @feature('shop.gift_cards')<a href="{{ url('/gift-cards') }}" class="hover:underline">Gift cards</a>@endfeature
            @feature('site.help_centre')<a href="{{ url('/help') }}" class="hover:underline">Help and contact</a>@endfeature
        </nav>
        <nav class="grid content-start gap-2" aria-label="Policies">
            @foreach ($pages as $p)<a href="{{ url('/p/'.$p->slug) }}" class="hover:underline">{{ $p->title }}</a>@endforeach
        </nav>
    </div>
    <p class="container-km muted mt-8 text-xs">© {{ now()->year }} {{ \App\Support\Settings::string('site.name') }}. Prices in {{ \App\Support\Currency::code() }}. We use only essential cookies ({{ \App\Membership\Benefits::join(['sign-in', \App\Support\Features::enabled('shop') ? 'cart' : null, \App\Support\Features::enabled('referral') ? 'referral code' : null]) }}) — no tracking.</p>
</footer>
