@php($pages = \App\Models\Page::query()->where('is_published', true)->where('in_footer', true)->orderBy('position')->get(['slug', 'title']))
<footer class="mt-16 border-t border-sand-200 bg-sand-100/60 py-10 text-sm dark:border-coffee-800 dark:bg-coffee-900">
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
            <a href="{{ url('/track') }}" class="hover:underline">Track your order</a>
        </nav>
        <nav class="grid content-start gap-2" aria-label="Policies">
            @foreach ($pages as $p)<a href="{{ url('/p/'.$p->slug) }}" class="hover:underline">{{ $p->title }}</a>@endforeach
        </nav>
    </div>
    <p class="container-km muted mt-8 text-xs">© {{ now()->year }} {{ \App\Support\Settings::string('site.name') }}. Prices in ETB. We use only essential cookies (cart and referral code) — no tracking.</p>
</footer>
