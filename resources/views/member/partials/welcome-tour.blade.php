{{-- Welcome tour: 3 short slides the first time (or ?tour=1 from Help). Slides about switched-off features are left out.
     Only the first slide is shown from the start (the others are `hidden`), so nothing jumps when the script loads.
     Without JS that first slide shows and "Got it" saves that it was seen (Help has the rest). JS: resources/js/chunks/welcome-tour.js. --}}
@php
    use App\Badges\BadgeIcons as I;
    use App\Support\Features as F;
    use App\Support\Money;
    use App\Support\Settings;
    $price = Settings::int('membership.price');
    $l1 = F::enabled('membership') && F::enabled('referral.level1') && F::enabled('referral.commission.membership')
        ? intdiv($price * Settings::int('referral.rate.membership.l1'), 10_000) : 0;
    $levels = count(array_filter([1, 2, 3], fn ($l) => F::enabled("referral.level$l")));
    $slides = array_values(array_filter([
        'welcome',
        $ref ? 'earn' : null,
        $ref || F::enabled('site.help_centre') ? 'start' : null,
    ]));
@endphp
<div class="fixed inset-0 z-50 grid place-items-end bg-ink-900/60 sm:place-items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="tour-title" data-tour>
    <div class="m-card relative max-h-[90svh] w-full overflow-y-auto rounded-b-none sm:max-w-md sm:rounded-2xl">
        <div class="mb-3 flex items-center justify-between gap-2">
            <div class="flex gap-1.5" aria-hidden="true" data-tour-dots>
                @foreach ($slides as $i => $s)<span class="h-1.5 w-6 rounded-full {{ $i === 0 ? '' : 'opacity-30' }}" style="background: var(--m-fill)"></span>@endforeach
            </div>
            <form method="post" action="{{ url('/account/tour/seen') }}" data-tour-form>@csrf<button class="text-sm underline m-muted" data-tour-skip>Skip</button></form>
        </div>

        <section data-tour-slide="welcome" aria-labelledby="tour-title">
            <h2 id="tour-title" class="font-serif text-2xl font-semibold">Welcome, {{ $user->first_name }}! 👋</h2>
            <p class="mt-1 text-sm m-muted">Here is your dashboard. This is where things are:</p>
            <ul class="mt-4 grid gap-3 text-sm">
                @if ($ref)<li class="flex gap-3">{!! I::svg('trending-up', 20) !!}<span><b>Earnings</b>: what you earned from your team, at the top.</span></li>@endif
                @feature('wallet')<li class="flex gap-3">{!! I::svg('wallet', 20) !!}<span><b>Wallet</b>: money ready to {{ F::enabled('wallet.cashout') ? 'spend or cash out' : 'spend in the shop' }}.</span></li>@endfeature
                @if ($ref)<li class="flex gap-3">{!! I::svg('users', 20) !!}<span><b>Team</b>: the people you invited.</span></li>@endif
                @feature('shop')<li class="flex gap-3">{!! I::svg('store', 20) !!}<span><b>Shop</b>: {{ F::enabled('membership') && F::enabled('membership.member_discount') ? 'members pay less on every product' : 'our handmade products' }}.</span></li>@endfeature
                @feature('site.help_centre')<li class="flex gap-3">{!! I::svg('circle-help', 20) !!}<span><b>Help</b>: answers to every question, in the menu (<b>More → Help</b> on a phone).</span></li>@endfeature
            </ul>
        </section>

        @if ($ref)
        <section data-tour-slide="earn" aria-label="How you earn" hidden>
            <h2 class="font-serif text-2xl font-semibold">How you earn</h2>
            <ol class="mt-4 grid gap-3 text-sm">
                <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white" style="background: var(--m-fill)">1</span><span>Share your link or your code <b class="font-mono">{{ $ref['code'] }}</b>.</span></li>
                <li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white" style="background: var(--m-fill)">2</span><span>When people you invite pay, you earn. You also earn from the people they invite ({{ $levels }} {{ $levels === 1 ? 'level' : 'levels' }}).</span></li>
                @if ($l1 > 0)<li class="flex gap-3"><span class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white" style="background: var(--m-fill)">3</span><span>Example: 3 friends join → <b data-tour-example>{{ Money::format(3 * $l1) }} every month</b> they stay ({{ Money::format($l1) }} from each {{ Money::format($price) }} membership).</span></li>@endif
            </ol>
            <p class="mt-4 rounded-xl px-3 py-2 text-sm m-soft">Earnings are <b>on the way</b> for {{ Settings::int('referral.pending_days') }} days, then <b>ready to use</b>. You earn only while your own membership is paid.</p>
        </section>
        @endif

        @if (in_array('start', $slides, true))
        <section data-tour-slide="start" aria-label="Start now" @if (count($slides) > 1) hidden @endif>
            <h2 class="font-serif text-2xl font-semibold">Start now</h2>
            @if ($ref)
            <div class="mt-4 grid gap-2" data-share-block>
                <div class="flex gap-2"><input class="m-input font-mono text-sm" readonly value="{{ $ref['link'] }}" data-copy-source aria-label="Your link"><button type="button" class="m-btn" data-copy>{!! I::svg('copy', 16) !!}Copy</button></div>
                @if ($ref['share'])<a class="m-btn-ghost" href="{{ $ref['share']['whatsapp'] }}" target="_blank" rel="noopener">{!! I::svg('message-circle', 16) !!}Share on WhatsApp</a>@endif
            </div>
            <p class="mt-3 text-xs m-muted">Tell people what they get, honestly. Never promise an income.</p>
            @endif
            @feature('site.help_centre')<a class="m-btn-ghost mt-3 w-full" href="{{ url('/help') }}">{!! I::svg('circle-help', 16) !!}Open Help</a>@endfeature
        </section>
        @endif

        <div class="mt-5 flex items-center gap-2">
            <button type="button" class="m-btn-ghost hidden" data-tour-back>Back</button>
            <form method="post" action="{{ url('/account/tour/seen') }}" class="ms-auto" data-tour-form>@csrf
                <button class="m-btn" data-tour-next>Got it</button>
            </form>
        </div>
    </div>
</div>
