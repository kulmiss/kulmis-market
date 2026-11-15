@php
    use App\Badges\BadgeIcons as I;
    use App\Support\Features as F;
    $fmt = fn ($v) => \App\Support\Money::format((int) $v);
    $day = fn ($c) => $c?->timezone(config('app.timezone'))->format('j M Y');
    $m = $dash['membership'];
    $money = $dash['money'];
    $ref = $dash['referral'];
    $badges = $dash['badges'];
    $statusChip = match (true) {
        $m['status'] === 'grace' => ['Grace period until '.$day($m['grace_until']), 'warn'],
        $m['active'] => ['Active member · renews '.$day($m['ends']), 'ok'],
        $m['status'] === 'none' => ['Not a member yet', 'muted'],
        default => ['Membership ended '.$day($m['ends']), 'warn'],
    };
@endphp
@extends('layouts.member', ['title' => 'Home'])
@section('content')
{{-- Greeting + quick actions --}}
<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm m-muted">Selam,</p>
        <h1 class="font-serif text-3xl font-semibold sm:text-4xl">{{ $user->first_name }}</h1>
        <span @class(['mt-2 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold',
            'm-soft m-accent-text' => $statusChip[1] === 'ok', 'bg-amber-500/15 text-amber-700 dark:text-amber-300' => $statusChip[1] === 'warn',
            'bg-black/5 dark:bg-white/10' => $statusChip[1] === 'muted']) data-membership-status>{!! I::svg($statusChip[1] === 'ok' ? 'circle-check' : 'clock', 14) !!}{{ $statusChip[0] }}</span>
    </div>
    <div class="grid w-full grid-cols-3 gap-2 sm:flex sm:w-auto" aria-label="Quick actions">
        @feature('wallet')<a href="{{ url('/account/wallet') }}" class="m-btn">{!! I::svg('banknote', 17) !!}Cash out</a>@endfeature
        @feature('shop')<a href="{{ url('/shop') }}" class="m-btn-ghost">{!! I::svg('store', 17) !!}Shop</a>@endfeature
        @feature('membership')<a href="#membership" class="m-btn-ghost">{!! I::svg('refresh-cw', 17) !!}{{ $m['active'] ? 'Renew' : 'Join' }}</a>@endfeature
    </div>
</div>

@if ($ref && ! $ref['earning'])<p class="mt-4 rounded-xl px-4 py-3 text-sm m-soft">Become an active member to earn from your team.</p>@endif
@if ($ref && $ref['under_review'])<p class="mt-3 rounded-xl bg-amber-500/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">Some of your earnings are being checked by our team. They stay safe and will be released after the check.</p>@endif

@if ($dash['is_new'])
    {{-- Empty state for new members --}}
    <section class="m-card mt-5 overflow-hidden p-0" data-empty-state>
        <div class="grid gap-4 p-5 sm:grid-cols-[1fr_auto] sm:items-center" style="background: linear-gradient(135deg, var(--m-soft), transparent)">
            <div>
                <h2 class="text-xl font-semibold">Welcome to {{ \App\Support\Settings::string('site.name') }}! 🎉</h2>
                <p class="mt-1 text-sm m-muted">Your dashboard fills up as your team grows. Here's how to start:</p>
                <ol class="mt-3 grid gap-2 text-sm">
                    <li class="flex gap-2"><span class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white" style="background: var(--m-accent)">1</span>Share your link or code with friends and family.</li>
                    <li class="flex gap-2"><span class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white" style="background: var(--m-accent)">2</span>They join and shop. You earn from 3 levels.</li>
                    <li class="flex gap-2"><span class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white" style="background: var(--m-accent)">3</span>Earnings are ready after {{ \App\Support\Settings::int('referral.pending_days') }} days. Cash out to Telebirr or your bank.</li>
                </ol>
            </div>
            @if ($ref)<a href="#referral" class="m-btn">{!! I::svg('share-2', 17) !!}{{ $ref['share'] ? 'Share my link' : 'My link' }}</a>@endif
        </div>
    </section>
@endif

{{-- Money --}}
@if (F::enabled('wallet') || $ref)
<section class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Your money">
    @include('member.partials.stat', ['label' => 'Total earned', 'value' => $fmt($money['earned']), 'icon' => 'coins', 'tone' => 'accent', 'sub' => 'All time, after refunds'])
    @include('member.partials.stat', ['label' => 'Pending', 'value' => $fmt($money['pending']), 'icon' => 'clock', 'sub' => \App\Support\Settings::int('referral.pending_days').'-day refund window'])
    @include('member.partials.stat', ['label' => 'Available', 'value' => $fmt($money['available']), 'icon' => 'wallet', 'sub' => 'Ready to cash out'])
    @include('member.partials.stat', ['label' => 'Paid out', 'value' => $fmt($money['paid_out']), 'icon' => 'banknote', 'sub' => $money['requested'] > 0 ? $fmt($money['requested']).' requested' : 'To Telebirr / bank'])
</section>
@endif

@if ($ref)
<section class="mt-3 grid gap-3 lg:grid-cols-3">
    <div class="m-card flex flex-col justify-between gap-3">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide m-muted">This month</p>
            <p class="mt-1 text-3xl font-bold tabular-nums">{{ $fmt($money['this_month']) }}</p>
            <p class="mt-1 text-sm m-muted">Last month {{ $fmt($money['last_month']) }}</p>
        </div>
        @if ($money['change'] !== null)
            <span @class(['inline-flex w-fit items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold', 'm-soft m-accent-text' => $money['change'] >= 0, 'bg-red-500/10 text-red-700 dark:text-red-300' => $money['change'] < 0])>
                {!! I::svg($money['change'] >= 0 ? 'trending-up' : 'trending-down', 14) !!}{{ $money['change'] >= 0 ? '+' : '' }}{{ $money['change'] }}% vs last month</span>
        @else
            <span class="text-xs m-muted">Compare with next month once you have earnings.</span>
        @endif
    </div>
    <div class="m-card lg:col-span-2">
        <div class="flex items-center justify-between gap-2">
            <h2 class="font-semibold">Earnings</h2>
            <div class="flex rounded-xl p-1 text-xs font-semibold m-soft" role="tablist" aria-label="Chart period">
                <a href="{{ url('/account?chart=daily') }}" role="tab" @class(['rounded-lg px-3 py-1', 'text-white' => $dash['chart_mode'] === 'daily']) @if ($dash['chart_mode'] === 'daily') aria-selected="true" style="background: var(--m-accent)" @endif>Daily</a>
                <a href="{{ url('/account?chart=monthly') }}" role="tab" @class(['rounded-lg px-3 py-1', 'text-white' => $dash['chart_mode'] === 'monthly']) @if ($dash['chart_mode'] === 'monthly') aria-selected="true" style="background: var(--m-accent)" @endif>Monthly</a>
            </div>
        </div>
        @if (array_sum(array_column($dash['chart'], 'value')) > 0)
            <div class="mt-3">@include('member.partials.chart', ['points' => $dash['chart']])</div>
        @else
            <div class="mt-3 grid h-44 place-items-center rounded-xl text-sm m-muted m-soft">No earnings yet. They'll show here day by day.</div>
        @endif
    </div>
</section>

<section class="mt-3 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
    @php $lvMax = max(1, max($dash['by_level'])); $tyMax = max(1, max($dash['by_type'])); @endphp
    <div class="m-card">
        <h2 class="font-semibold">Earnings per level</h2>
        <ul class="mt-3 grid gap-3">
            @foreach ($dash['by_level'] as $lvl => $amt)
                <li><div class="flex justify-between text-sm"><span>Level {{ $lvl }}</span><b class="tabular-nums">{{ $fmt($amt) }}</b></div>
                    <div class="mt-1 h-2 rounded-full m-soft"><div class="h-2 rounded-full" style="width: {{ $amt > 0 ? max(3, $amt / $lvMax * 100) : 0 }}%; background: var(--m-accent)"></div></div></li>
            @endforeach
        </ul>
    </div>
    <div class="m-card">
        <h2 class="font-semibold">By type</h2>
        <ul class="mt-3 grid gap-3">
            @foreach (['membership' => 'Memberships', 'products' => 'Shop orders', 'signals' => 'Signals'] as $k => $label)
                <li><div class="flex justify-between text-sm"><span>{{ $label }}</span><b class="tabular-nums">{{ $fmt($dash['by_type'][$k]) }}</b></div>
                    <div class="mt-1 h-2 rounded-full m-soft"><div class="h-2 rounded-full" style="width: {{ $dash['by_type'][$k] > 0 ? max(3, $dash['by_type'][$k] / $tyMax * 100) : 0 }}%; background: var(--m-accent)"></div></div></li>
            @endforeach
        </ul>
    </div>
    @if ($badges)
    <a href="{{ url('/account/badges') }}" class="m-card block md:col-span-2 lg:col-span-1">
        <div class="flex items-center justify-between gap-2"><h2 class="font-semibold">Next badge</h2>
            @if ($badges['highest'])@include('store.partials.badge-chip', ['b' => $badges['highest'], 'size' => 14])@endif</div>
        @if ($badges['next'])
            <div class="mt-3">@include('store.account.partials.badge-progress', ['b' => $badges['next']])</div>
        @else
            <p class="mt-3 text-sm m-muted">You've unlocked every badge. Amazing!</p>
        @endif
        <p class="mt-3 text-xs m-accent-text">{{ $badges['unlocked'] }} of {{ $badges['total'] }} badges unlocked →</p>
    </a>
    @endif
</section>

@if ($bonus = $dash['bonus'])
<section class="m-card mt-3" id="badge-bonus" aria-labelledby="bonus-title">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 id="bonus-title" class="font-semibold">Your badge bonus</h2>
            @if ($bonus['current'])
                <p class="mt-1 text-sm">Badge now: <b style="color: {{ $bonus['current']['color'] }}">{{ $bonus['current']['name'] }}</b> · {{ $bonus['active'] }} active {{ \Illuminate\Support\Str::plural('member', $bonus['active']) }} you invited</p>
            @else
                <p class="mt-1 text-sm m-muted">{{ $bonus['active'] }} active {{ \Illuminate\Support\Str::plural('member', $bonus['active']) }} you invited. Bronze starts at {{ $bonus['next']['threshold'] ?? 3 }}.</p>
            @endif
        </div>
        <div class="text-end">
            <p class="text-xs m-muted">Expected for {{ $bonus['month'] }}</p>
            <p class="text-2xl font-semibold tabular-nums break-all">{{ $fmt($bonus['expected']) }}</p>
        </div>
    </div>
    @if (! $bonus['paid_all_month'])
        <p class="mt-2 rounded-xl px-3 py-2 text-sm m-soft">No bonus this month: your membership was not paid for every day of {{ $bonus['month'] }}. Keep it paid all of next month to get one.</p>
    @else
        <p class="mt-2 text-xs m-muted">We pay it on {{ $bonus['pay_day'] }} if your membership stays paid every day and your team still has enough active members at the end of the month.</p>
    @endif
    @if ($bonus['adjusted_last'])
        <p class="mt-2 rounded-xl bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-200"><b>Bonus adjusted this month.</b> Many members earned a bonus, so everyone got the same smaller share.</p>
    @endif
    @if ($bonus['next'])
        <div class="mt-4">
            <div class="flex items-baseline justify-between gap-2 text-sm"><span>Next: <b>{{ $bonus['next']['name'] }}</b> · {{ $fmt($bonus['next']['bonus']) }} a month</span><span class="tabular-nums m-muted">{{ $bonus['active'] }}/{{ $bonus['next']['threshold'] }}</span></div>
            <div class="mt-1 h-3 overflow-hidden rounded-full m-soft" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $bonus['next']['percent'] }}" aria-label="Progress to {{ $bonus['next']['name'] }}">
                <div class="h-full rounded-full" style="width: {{ max(2, $bonus['next']['percent']) }}%; background: {{ $bonus['next']['color'] }}"></div>
            </div>
            <p class="mt-1 text-xs m-muted">{{ $bonus['next']['missing'] }} more active {{ \Illuminate\Support\Str::plural('member', $bonus['next']['missing']) }} to go.</p>
        </div>
    @else
        <p class="mt-4 text-sm">You have the highest badge. Amazing!</p>
    @endif
    @if ($bonus['history'])
        <details class="mt-4" data-collapse="bonus-history">
            <summary class="flex min-h-12 cursor-pointer items-center text-sm font-semibold">Bonus history ({{ count($bonus['history']) }})</summary>
            <ul class="grid gap-2 text-sm">
                @foreach ($bonus['history'] as $h)
                    <li class="flex items-center justify-between gap-3 rounded-xl px-3 py-2 m-soft">
                        <span class="min-w-0"><b>{{ $h['month'] }}</b> · {{ $h['badge'] }}{{ $h['kind'] === 'one_time' ? ' (one time)' : '' }}<span class="block text-xs m-muted">{{ $h['status'] }}{{ $h['adjusted'] ? ' · adjusted' : '' }}</span></span>
                        <b class="shrink-0 tabular-nums">{{ $fmt($h['amount']) }}</b>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</section>
@endif

{{-- Referral + team --}}
<section class="mt-3 grid gap-3 lg:grid-cols-3">
    <div class="m-card lg:col-span-2" id="referral">
        <h2 class="font-semibold">Your referral link</h2>
        <div class="mt-3 grid gap-4 sm:grid-cols-[1fr_auto]">
            <div class="grid content-start gap-3">
                <div class="flex gap-2"><input class="m-input font-mono text-sm" readonly value="{{ $ref['link'] }}" data-copy-source aria-label="Referral link"><button type="button" class="m-btn" data-copy>{!! I::svg('copy', 16) !!}Copy</button></div>
                <p class="text-sm">Code: <b class="font-mono text-lg tracking-wider">{{ $ref['code'] }}</b></p>
                @if ($ref['share'])
                <div class="grid grid-cols-2 gap-2 sm:flex">
                    <a class="m-btn-ghost" href="{{ $ref['share']['telegram'] }}" target="_blank" rel="noopener">{!! I::svg('send', 16) !!}Telegram</a>
                    <a class="m-btn-ghost" href="{{ $ref['share']['whatsapp'] }}" target="_blank" rel="noopener">{!! I::svg('message-circle', 16) !!}WhatsApp</a>
                    <button type="button" class="m-btn-ghost hidden" data-share data-share-url="{{ $ref['link'] }}" data-share-text="Join me on {{ \App\Support\Settings::string('site.name') }}. Use my code {{ $ref['code'] }}">{!! I::svg('share-2', 16) !!}More</button>
                </div>
                @endif
                @if ($ref['can_change_code'])
                    <details class="text-sm"><summary class="cursor-pointer m-muted">Choose your own code (once)</summary>
                        <form method="post" action="{{ url('/account/referral-code') }}" class="mt-2 flex gap-2">@csrf<input name="code" class="m-input uppercase" placeholder="e.g. SELAM25" maxlength="12" aria-label="New code"><button class="m-btn-ghost">Save</button></form>
                    </details>
                @endif
            </div>
            @if ($ref['qr_url'])
            <figure class="justify-self-center text-center">
                <img src="{{ url('/account/referral-qr.svg') }}" alt="QR code for your referral link" width="140" height="140" class="rounded-xl bg-white p-2" loading="lazy">
                <figcaption class="mt-1 text-xs m-muted">Scan to join</figcaption>
            </figure>
            @endif
        </div>
    </div>
    <div class="m-card">
        <div class="flex items-center justify-between"><h2 class="font-semibold">Your team</h2><a href="{{ url('/account/team') }}" class="text-xs m-accent-text">See all →</a></div>
        <ul class="mt-3 grid gap-2">
            @foreach ([1 => [$ref['team']['level1_count'], $ref['team']['level1_active']], 2 => [$ref['team']['level2_count'], null], 3 => [$ref['team']['level3_count'], null]] as $lvl => [$count, $active])
                <li class="flex items-center justify-between rounded-xl px-3 py-2.5 m-soft">
                    <span class="text-sm font-medium">Level {{ $lvl }}</span>
                    <span class="text-sm"><b class="text-lg tabular-nums">{{ $count }}</b>@if ($active !== null) <span class="m-muted">· {{ $active }} active · {{ $count - $active }} not active</span>@endif</span>
                </li>
            @endforeach
        </ul>
    </div>
</section>

<section class="mt-3 grid gap-3 lg:grid-cols-3">
    <div class="m-card">
        <h2 class="font-semibold">Newest in your team</h2>
        @forelse ($dash['newest'] as $p)
            <div class="mt-3 flex items-center gap-3">
                <span class="grid size-9 shrink-0 place-items-center rounded-full text-sm font-bold m-soft m-accent-text">{{ mb_substr($p['name'], 0, 1) }}</span>
                <div class="min-w-0 flex-1"><p class="truncate text-sm font-medium">{{ $p['name'] }}</p><p class="text-xs m-muted">Level {{ $p['level'] }} · joined {{ $p['joined'] }}</p></div>
                <span @class(['rounded-full px-2 py-0.5 text-[11px] font-semibold', 'm-soft m-accent-text' => $p['active'], 'bg-black/5 m-muted dark:bg-white/10' => ! $p['active']])>{{ $p['active'] ? 'active' : 'not active' }}</span>
            </div>
        @empty
            <div class="mt-3 grid place-items-center gap-2 rounded-xl p-6 text-center text-sm m-soft">{!! I::svg('user-plus', 26) !!}<p>No one yet. Share your link: the first people who join show up here.</p></div>
        @endforelse
    </div>
@endif

    @feature('membership')
    <div class="m-card" id="membership">
        <h2 class="font-semibold">Membership</h2>
        @if ($m['active'])
            <p class="mt-1 text-sm">Active until <b>{{ $day($m['ends']) }}</b>@if ($m['grace_until']) · grace until {{ $day($m['grace_until']) }}@endif</p>
            <div class="mt-3 flex items-center gap-3 rounded-xl p-3 m-soft">
                <span class="text-2xl">🔥</span>
                <div class="text-sm"><b>{{ $m['streak_months'] }} {{ \Illuminate\Support\Str::plural('month', $m['streak_months']) }}</b> in a row
                    @if ($m['next_reward_in'])<span class="block text-xs m-muted">{{ $fmt($m['reward']) }} loyalty reward in {{ $m['next_reward_in'] }} {{ \Illuminate\Support\Str::plural('month', $m['next_reward_in']) }}</span>@endif</div>
            </div>
        @else
            <p class="mt-1 text-sm m-muted">{{ $fmt($m['price']) }} per month: member prices, trading signals and earnings from your team.</p>
        @endif
        @if (F::enabled('wallet.spend') && $wallet['available'] >= $m['price'])
        <form method="post" action="{{ url('/account/membership/pay') }}" class="mt-3 grid gap-2">
            @csrf
            <button name="method" value="wallet" class="m-btn">{{ $isMember ? 'Renew' : 'Join' }} from my wallet · {{ $fmt($m['price']) }}</button>
        </form>
        @endif
        @feature('payments.manual')
        <details class="mt-3 text-sm" @if (! (F::enabled('wallet.spend') && $wallet['available'] >= $m['price'])) open @endif><summary class="cursor-pointer font-medium">{{ $isMember ? 'Renew' : 'Join' }} by Telebirr or bank transfer · {{ $fmt($m['price']) }}</summary>
            <div class="mt-3 grid gap-2 rounded-xl p-3 text-xs m-soft">
                <pre class="whitespace-pre-wrap font-sans">{{ \App\Support\Settings::string('payments.telebirr_details') }}</pre>
                <pre class="whitespace-pre-wrap font-sans">{{ \App\Support\Settings::string('payments.bank_details') }}</pre>
            </div>
            <form method="post" action="{{ url('/account/membership/proof') }}" enctype="multipart/form-data" class="mt-3 grid gap-2">
                @csrf
                <select name="method" class="m-input" aria-label="How you paid"><option value="telebirr">Telebirr</option><option value="bank_transfer">Bank transfer</option></select>
                <input name="reference" class="m-input" placeholder="Transaction reference" aria-label="Transaction reference">
                <input name="proof" type="file" accept="image/*,application/pdf" class="m-input" required aria-label="Receipt photo or PDF">
                @if (F::enabled('wallet.spend') && $wallet['available'] >= 100 && $wallet['available'] < $m['price'])
                    <label class="flex items-center gap-2"><input type="checkbox" name="use_wallet" value="1" class="size-4"> Use my wallet ({{ $fmt($wallet['available']) }}) and transfer only the rest</label>
                @endif
                <button class="m-btn">Send receipt</button>
            </form>
        </details>
        @endfeature
    </div>
    @endfeature

    @feature('signals')
    <div class="m-card">
        <h2 class="font-semibold">Trading signals</h2>
        @if ($signalsLink)
            <p class="mt-1 text-sm m-muted">Included in your membership. Open your personal link in Telegram (don't share it).</p>
            <a class="m-btn mt-3 w-full" href="{{ $signalsLink }}" rel="noopener">{!! I::svg('send', 17) !!}Open in Telegram</a>
            <a class="mt-2 block text-center text-xs m-accent-text" href="{{ url('/account/signals') }}">Recent results →</a>
        @elseif ($isMember)
            <p class="mt-1 text-sm m-muted">Included in your membership. Your personal Telegram link appears here as soon as our Telegram bot is connected.</p>
            <a class="mt-3 block text-center text-xs m-accent-text" href="{{ url('/account/signals') }}">Recent results →</a>
        @else
            <p class="mt-1 text-sm m-muted">Members get our private Telegram channel with trading signals.</p>
            <a class="m-btn-ghost mt-3 w-full" href="#membership">{!! I::svg('radio', 17) !!}Get access with membership</a>
        @endif
    </div>
    @endfeature
@if ($ref)
</section>
@endif

{{-- Recent activity --}}
<section class="m-card mt-3">
    <h2 class="font-semibold">Recent activity</h2>
    @forelse ($dash['activity'] as $a)
        <div class="mt-3 flex items-center gap-3">
            <span @class(['grid size-9 shrink-0 place-items-center rounded-full', 'm-soft m-accent-text' => in_array($a['tone'], ['accent', 'neutral'], true),
                'bg-amber-500/15 text-amber-600' => $a['tone'] === 'gold', 'bg-red-500/10 text-red-600' => $a['tone'] === 'warn'])>{!! I::svg($a['icon'], 17) !!}</span>
            <div class="min-w-0 flex-1"><p class="truncate text-sm">{{ $a['text'] }}</p><p class="text-xs m-muted">{{ $a['when']?->timezone(config('app.timezone'))->diffForHumans() }}</p></div>
            @if ($a['amount'] !== null)<b @class(['text-sm tabular-nums', 'm-accent-text' => $a['amount'] > 0])>{{ $a['amount'] > 0 ? '+' : '' }}{{ $fmt($a['amount']) }}</b>@endif
        </div>
    @empty
        <p class="mt-3 text-sm m-muted">Nothing yet. Your earnings, orders and badges will show here.</p>
    @endforelse
</section>

@if ($earlyAccess->isNotEmpty())
<section class="mt-6">
    <h2 class="text-xl font-semibold">Early access for you</h2>
    <div class="mt-3 grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-6">@foreach ($earlyAccess as $p)@include('store.partials.product-card', ['p' => $p])@endforeach</div>
</section>
@endif
@if ($badges)@include('store.account.partials.badge-celebration', ['unseen' => $badges['unseen']])@endif
@endsection
