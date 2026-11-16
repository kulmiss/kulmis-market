@php
    use App\Badges\BadgeIcons as I;
    use App\Support\Features as F;
    $fmt = fn ($v) => \App\Support\Money::format((int) $v);
    $day = fn ($c) => $c?->timezone(config('app.timezone'))->format('j M Y');
    $m = $dash['membership'];
    $money = $dash['money'];
    $ref = $dash['referral'];
    $badges = $dash['badges'];
    // A receipt for the membership is waiting for the shop to check it.
    $checking = \App\Models\Payment::query()->where('status', \App\Models\Payment::AWAITING_REVIEW)
        ->whereHasMorph('payable', [\App\Models\MembershipPurchase::class], fn ($q) => $q->where('user_id', auth()->id()))->exists();
    // …or the last one was not accepted (staff wrote why).
    $lastReceipt = $checking ? null : \App\Models\Payment::query()->whereHasMorph('payable', [\App\Models\MembershipPurchase::class], fn ($q) => $q->where('user_id', auth()->id()))
        ->whereNotNull('proof_path')->latest('id')->first();
    $rejected = $lastReceipt && $lastReceipt->status === \App\Models\Payment::REJECTED ? $lastReceipt : null;
    $statusChip = match (true) {
        $checking && ! $m['active'] => ['Receipt sent · we are checking it', 'muted'],
        $rejected && ! $m['active'] => ['Receipt not accepted · please send a new one', 'warn'],
        $m['status'] === 'grace' => ['Please pay: your benefits stop on '.$day($m['grace_until']), 'warn'],
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
        @if (F::enabled('membership'))<span @class(['mt-2 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold',
            'm-soft m-accent-text' => $statusChip[1] === 'ok', 'bg-amber-500/15 text-amber-700 dark:text-amber-300' => $statusChip[1] === 'warn',
            'bg-black/5 dark:bg-white/10' => $statusChip[1] === 'muted']) data-membership-status>{!! I::svg($statusChip[1] === 'ok' ? 'circle-check' : 'clock', 14) !!}{{ $statusChip[0] }}</span>@endif
    </div>
    <div class="grid w-full grid-cols-3 gap-2 sm:flex sm:w-auto" aria-label="Quick actions">
        {{-- The main button is what this person can do next: join, or cash out when there is enough ready money. --}}
        @php $canCashOut = F::enabled('wallet.cashout') && $wallet['available'] >= \App\Support\Settings::int('wallet.cashout_min'); @endphp
        @if (F::enabled('membership') && ! $m['active'] && ! $checking)<a href="#membership" class="m-btn">{!! I::svg('user-plus', 17) !!}Join</a>@endif
        @feature('wallet')<a href="{{ url('/account/wallet') }}" @class(['m-btn' => $canCashOut, 'm-btn-ghost' => ! $canCashOut])>{!! I::svg('banknote', 17) !!}{{ $canCashOut ? 'Cash out' : 'My wallet' }}</a>@endfeature
        @feature('shop')<a href="{{ url('/shop') }}" class="m-btn-ghost">{!! I::svg('store', 17) !!}Shop</a>@endfeature
        @if (F::enabled('membership') && $m['active'])<a href="#membership" class="m-btn-ghost">{!! I::svg('refresh-cw', 17) !!}Renew</a>@endif
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
                @if ($ref)
                <p class="mt-1 text-sm m-muted">Your dashboard fills up as your team grows. Here's how to start:</p>
                <ol class="mt-3 grid gap-2 text-sm">
                    <li class="flex gap-2"><span class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white" style="background: var(--m-accent)">1</span>Share your link or code with friends and family.</li>
                    <li class="flex gap-2"><span class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white" style="background: var(--m-accent)">2</span>They join{{ F::enabled('shop') ? ' and shop' : '' }}. You earn from {{ count(array_filter([1, 2, 3], fn ($l) => F::enabled("referral.level$l"))) }} {{ count(array_filter([1, 2, 3], fn ($l) => F::enabled("referral.level$l"))) === 1 ? 'level' : 'levels' }}.</li>
                    <li class="flex gap-2"><span class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-bold text-white" style="background: var(--m-accent)">3</span>Earnings are ready after {{ \App\Support\Settings::int('referral.pending_days') }} days.@feature('wallet.cashout') Cash out to Telebirr or your bank.@endfeature</li>
                </ol>
                @else
                <p class="mt-1 text-sm m-muted">Your {{ \App\Membership\Benefits::join([F::enabled('shop') ? 'orders' : null, F::enabled('signals') ? 'signals' : null, 'news']) }} will show here.</p>
                @endif
            </div>
            @if ($ref)<a href="#referral" class="m-btn">{!! I::svg('share-2', 17) !!}{{ $ref['share'] ? 'Share my link' : 'My link' }}</a>@endif
        </div>
    </section>
@endif

{{-- Money --}}
@if (F::enabled('wallet') || $ref)
<section class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Your money">
    @include('member.partials.stat', ['label' => 'Total earned', 'value' => $fmt($money['earned']), 'icon' => 'coins', 'tone' => 'accent', 'sub' => 'Since you joined, after money given back'])
    @include('member.partials.stat', ['label' => 'On the way', 'value' => $fmt($money['pending']), 'icon' => 'clock', 'sub' => 'Ready to use in '.\App\Support\Settings::int('referral.pending_days').' days'])
    @include('member.partials.stat', ['label' => 'Ready to use', 'value' => $fmt($money['available']), 'icon' => 'wallet', 'sub' => $money['available'] < 0 ? 'Money given back: your next earnings pay this first' : (F::enabled('wallet.cashout') ? 'You can cash it out or spend it' : (F::enabled('wallet.spend') ? 'You can spend it' : 'Earnings that are ready'))])
    @if (F::enabled('wallet.cashout'))@include('member.partials.stat', ['label' => 'Paid out', 'value' => $fmt($money['paid_out']), 'icon' => 'banknote', 'sub' => $money['requested'] > 0 ? $fmt($money['requested']).' asked for' : 'To Telebirr / bank'])@endif
</section>
@endif

@if ($ref)
<section class="mt-3 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
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
        @feature('account.goals')
            @php $goal = \App\Referral\Goals::progress($user, $money['this_month']); @endphp
            <div class="border-t pt-3" style="border-color: var(--m-line)" id="goal">
                @if ($goal)
                    <div class="flex items-baseline justify-between gap-2 text-sm">
                        <span class="font-semibold">Goal: {{ $fmt($goal['goal']) }}</span>
                        <span class="tabular-nums m-muted">{{ $goal['percent'] }}%</span>
                    </div>
                    <div class="mt-2 h-2.5 overflow-hidden rounded-full m-soft" role="progressbar" aria-label="Monthly goal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $goal['percent'] }}">
                        <div class="h-full rounded-full" style="width: {{ $goal['percent'] }}%; background: var(--m-accent)"></div>
                    </div>
                    <p class="mt-2 text-xs m-muted">{{ $goal['reached'] ? 'Goal reached this month. Well done! 🎉' : $fmt($goal['left']).' to go this month.' }}</p>
                @endif
                <details class="mt-1" @if (! $goal) open @endif>
                    <summary class="inline-flex min-h-12 cursor-pointer items-center text-sm font-semibold m-accent-text">{{ $goal ? 'Change my goal' : 'Set a goal for this month' }}</summary>
                    <form method="post" action="{{ url('/account/goal') }}" class="flex gap-2">@csrf
                        <label class="sr-only" for="goal-input">Goal in {{ \App\Support\Currency::code() }}</label>
                        <input id="goal-input" name="goal" type="number" inputmode="numeric" min="0" step="1" class="m-input" placeholder="e.g. 3000" value="{{ $goal ? intdiv($goal['goal'], 100) : '' }}">
                        <button class="m-btn">Save</button>
                    </form>
                </details>
            </div>
        @endfeature
    </div>
    <div class="m-card lg:col-span-2">
        <div class="flex items-center justify-between gap-2">
            <h2 class="font-semibold">Earnings</h2>
            <div class="flex rounded-xl p-1 text-xs font-semibold m-soft" role="tablist" aria-label="Chart period">
                <a href="{{ url('/account?chart=daily') }}" role="tab" @class(['inline-flex min-h-12 items-center rounded-lg px-3 sm:min-h-8', 'text-white' => $dash['chart_mode'] === 'daily']) @if ($dash['chart_mode'] === 'daily') aria-selected="true" style="background: var(--m-accent)" @endif>Daily</a>
                <a href="{{ url('/account?chart=monthly') }}" role="tab" @class(['inline-flex min-h-12 items-center rounded-lg px-3 sm:min-h-8', 'text-white' => $dash['chart_mode'] === 'monthly']) @if ($dash['chart_mode'] === 'monthly') aria-selected="true" style="background: var(--m-accent)" @endif>Monthly</a>
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
            @foreach (array_filter($dash['by_level'], fn ($l) => \App\Support\Features::enabled("referral.level$l"), ARRAY_FILTER_USE_KEY) as $lvl => $amt)
                <li><div class="flex justify-between text-sm"><span>Level {{ $lvl }}</span><b class="tabular-nums">{{ $fmt($amt) }}</b></div>
                    <div class="mt-1 h-2 rounded-full m-soft"><div class="h-2 rounded-full" style="width: {{ $amt > 0 ? max(3, $amt / $lvMax * 100) : 0 }}%; background: var(--m-accent)"></div></div></li>
            @endforeach
        </ul>
    </div>
    <div class="m-card">
        <h2 class="font-semibold">By type</h2>
        <ul class="mt-3 grid gap-3">
            @foreach (array_filter(['membership' => 'Memberships', 'products' => 'Shop orders', 'signals' => 'Signals'], fn ($k) => F::enabled($k === 'products' ? 'shop' : $k), ARRAY_FILTER_USE_KEY) as $k => $label)
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
@php $tips = $ref ? \App\Referral\Tips::for($user, $ref) : []; @endphp
@if (count($tips))
<section class="m-card mt-3" aria-labelledby="tips-h">
    <h2 id="tips-h" class="font-semibold">{!! I::svg('sparkles', 18) !!} Earn more: tips for you</h2>
    <ul class="mt-3 grid gap-3">
        @foreach ($tips as $t)
            <li class="rounded-xl p-3 text-sm m-soft">
                <p>{{ $t['text'] }}</p>
                @if (isset($t['share']))
                    <div class="mt-2 flex flex-wrap gap-2">
                        <a class="m-btn-ghost" href="{{ $t['share']['whatsapp'] }}" target="_blank" rel="noopener">{!! I::svg('message-circle', 16) !!}WhatsApp</a>
                        <a class="m-btn-ghost" href="{{ $t['share']['telegram'] }}" target="_blank" rel="noopener">{!! I::svg('send', 16) !!}Telegram</a>
                    </div>
                @elseif (isset($t['link']))
                    <a class="mt-1 inline-flex min-h-12 items-center font-semibold m-accent-text" href="{{ url($t['link']) }}">{{ $t['link_text'] }} →</a>
                @endif
            </li>
        @endforeach
    </ul>
</section>
@endif
@if ($ref)@include('member.partials.how-you-earn')@endif
<section class="mt-3 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
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
                    <details class="text-sm"><summary class="flex min-h-12 cursor-pointer items-center m-muted">Choose your own code (once)</summary>
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
        <div class="flex items-center justify-between"><h2 class="font-semibold">Your team</h2><a href="{{ url('/account/team') }}" class="inline-flex min-h-12 items-center text-xs m-accent-text">See all →</a></div>
        <ul class="mt-3 grid gap-2">
            @foreach (array_filter([1 => [$ref['team']['level1_count'], $ref['team']['level1_active']], 2 => [$ref['team']['level2_count'], null], 3 => [$ref['team']['level3_count'], null]], fn ($l) => \App\Support\Features::enabled("referral.level$l"), ARRAY_FILTER_USE_KEY) as $lvl => [$count, $active])
                <li class="flex items-center justify-between rounded-xl px-3 py-2.5 m-soft">
                    <span class="text-sm font-medium">Level {{ $lvl }}</span>
                    <span class="text-sm"><b class="text-lg tabular-nums">{{ $count }}</b>@if ($active !== null) <span class="m-muted">· {{ $active }} active · {{ $count - $active }} not active</span>@endif</span>
                </li>
            @endforeach
        </ul>
    </div>
</section>

<section class="mt-3 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
    <div class="m-card">
        <h2 class="font-semibold">Newest in your team</h2>
        @forelse (array_filter($dash['newest'], fn ($p) => \App\Support\Features::enabled('referral.level'.$p['level'])) as $p)
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
        <div class="flex items-center gap-2"><h2 class="font-semibold">Membership</h2>@include('member.partials.help-link', ['slug' => 'become-a-member', 'label' => 'membership'])</div>
        @if ($rejected)<p class="mt-2 rounded-xl bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-200" role="status" data-receipt-rejected><b>We could not accept your last receipt.</b> Reason: {{ $rejected->reject_reason }}. Please send a new one below.</p>@endif
        @if ($m['active'])
            <p class="mt-1 text-sm">Active until <b>{{ $day($m['ends']) }}</b>@if ($m['grace_until']) · if you pay late, benefits stop on {{ $day($m['grace_until']) }}@endif</p>
            <div class="mt-3 flex items-center gap-3 rounded-xl p-3 m-soft">
                <span class="text-2xl">🔥</span>
                <div class="text-sm"><b>{{ $m['streak_months'] }} {{ \Illuminate\Support\Str::plural('month', $m['streak_months']) }}</b> in a row
                    @if ($m['next_reward_in'])<span class="block text-xs m-muted">{{ $fmt($m['reward']) }} loyalty reward in {{ $m['next_reward_in'] }} {{ \Illuminate\Support\Str::plural('month', $m['next_reward_in']) }}</span>@endif</div>
            </div>
        @else
            <p class="mt-1 text-sm m-muted">{{ $fmt($m['price']) }} per month: {{ \App\Membership\Benefits::sentence() }}.</p>
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
            <p class="mt-1 text-sm m-muted">{{ F::enabled('membership') ? 'Included in your membership.' : 'You have access.' }} Open your personal link in Telegram (don't share it).</p>
            <a class="m-btn mt-3 w-full" href="{{ $signalsLink }}" rel="noopener">{!! I::svg('send', 17) !!}Open in Telegram</a>
            <a class="mt-2 flex min-h-12 items-center justify-center text-xs m-accent-text" href="{{ url('/account/signals') }}">Recent results →</a>
        @elseif ($isMember)
            <p class="mt-1 text-sm m-muted">{{ F::enabled('membership') ? 'Included in your membership.' : 'You have access.' }} Your personal Telegram link appears here as soon as our Telegram bot is connected.</p>
            <a class="mt-3 flex min-h-12 items-center justify-center text-xs m-accent-text" href="{{ url('/account/signals') }}">Recent results →</a>
        @else
            @if (F::enabled('membership'))
            <p class="mt-1 text-sm m-muted">Members get our private Telegram channel with trading signals.</p>
            <a class="m-btn-ghost mt-3 w-full" href="#membership">{!! I::svg('radio', 17) !!}Get access with membership</a>
            @else
            <p class="mt-1 text-sm m-muted">Our private Telegram channel with trading signals.</p>
            <a class="m-btn-ghost mt-3 w-full" href="{{ url('/signals') }}">{!! I::svg('radio', 17) !!}Get access</a>
            @endif
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
        <p class="mt-3 text-sm m-muted">Nothing yet. Your {{ \App\Membership\Benefits::join([F::enabled('referral') ? 'earnings' : null, F::enabled('shop') ? 'orders' : null, F::enabled('badges') ? 'badges' : null, F::enabled('wallet') ? 'money' : null]) ?: 'news' }} will show here.</p>
    @endforelse
</section>

@if ($earlyAccess->isNotEmpty())
<section class="mt-6">
    <h2 class="text-xl font-semibold">Early access for you</h2>
    <div class="mt-3 grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-6">@foreach ($earlyAccess as $p)@include('store.partials.product-card', ['p' => $p])@endforeach</div>
</section>
@endif
@php $showTour = \App\Support\Features::enabled('account.welcome_tour') && (! $user->welcome_tour_seen_at || request()->boolean('tour')); @endphp
@if ($showTour)@include('member.partials.welcome-tour')
@elseif ($badges)@include('store.account.partials.badge-celebration', ['unseen' => $badges['unseen']])@endif
@endsection
