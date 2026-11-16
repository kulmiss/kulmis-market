@php
    $r = fn ($t, $l) => rtrim(rtrim(number_format(\App\Support\Settings::int("referral.rate.$t.l$l") / 100, 2), '0'), '.').'%';
    $lv = fn ($l) => \App\Support\Features::enabled("referral.level$l");
@endphp
@extends('layouts.store', ['title' => 'Earn with referrals', 'description' => 'Invite friends with your link or code and earn on '.\App\Membership\Benefits::join([\App\Support\Features::enabled('membership') ? 'memberships' : null, \App\Support\Features::enabled('signals') ? 'signals' : null, \App\Support\Features::enabled('shop') ? 'orders' : null]).'.'])
@section('content')
<section class="container-km max-w-4xl py-12">
    <h1 class="text-4xl">Share Kulmis, earn every month.</h1>
    <p class="muted mt-4 text-lg">Every member gets a personal link and a short code (like <b>ABDI25</b>). When people you invite buy, you earn — on three levels.</p>
    <ol class="mt-8 grid gap-4 md:grid-cols-3">
        @feature('membership')<li class="card"><p class="text-3xl font-semibold text-brand-600">1</p><p class="mt-2 font-semibold">Become a member</p><p class="muted text-sm">Only active members earn.</p></li>@endfeature
        <li class="card"><p class="text-3xl font-semibold text-brand-600">2</p><p class="mt-2 font-semibold">Share your link or code</p><p class="muted text-sm">Telegram, WhatsApp{{ \App\Support\Features::enabled('referral.qr_codes') ? ' or a QR code' : ' or a message' }}. Anyone can use it — even without an account.</p></li>
        <li class="card"><p class="text-3xl font-semibold text-brand-600">3</p><p class="mt-2 font-semibold">Earn on their purchases</p><p class="muted text-sm">Earnings are ready to use after {{ \App\Support\Settings::int('referral.pending_days') }} days.</p></li>
    </ol>
    <div class="card mt-8 overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead><tr class="text-start"><th class="py-2 text-start">When someone in your team buys…</th>@foreach ([1, 2, 3] as $l)@if ($lv($l))<th class="text-start">Level {{ $l }}</th>@endif @endforeach</tr></thead>
            <tbody class="divide-y divide-mist-200 dark:divide-ink-700">
                @if (\App\Support\Features::enabled('referral.commission.membership') && \App\Support\Features::enabled('membership'))<tr><td class="py-2">A membership month</td>@foreach ([1, 2, 3] as $l)@if ($lv($l))<td>{{ $r('membership', $l) }}</td>@endif @endforeach</tr>@endif
                @if (\App\Support\Features::enabled('referral.commission.products') && \App\Support\Features::enabled('shop'))<tr><td class="py-2">Shop products (after discounts, without shipping)</td>@foreach ([1, 2, 3] as $l)@if ($lv($l))<td>{{ $r('products', $l) }}</td>@endif @endforeach</tr>@endif
                @if (\App\Support\Features::enabled('referral.commission.signals') && \App\Support\Features::enabled('signals'))<tr><td class="py-2">Signals (guest buyers)</td>@if ($lv(1))<td>{{ $r('signals', 1) }}</td>@endif @if ($lv(2))<td>—</td>@endif @if ($lv(3))<td>—</td>@endif</tr>@endif
            </tbody>
        </table>
        <p class="muted mt-3 text-xs">{{ implode(' ', array_filter([$lv(1) ? 'Level 1 = people you invite directly (no limit).' : null, $lv(2) ? 'Level 2 = people they invite.' : null, $lv(3) ? 'Level 3 = the next step.' : null])) }}{{ \App\Support\Features::enabled('membership') ? " If a member's membership has ended, that level's earning is skipped." : '' }}</p>
    </div>
    <div class="mt-6 grid gap-4 md:grid-cols-2">
        <div class="card text-sm"><p class="font-semibold">Use your earnings</p><p class="muted mt-1">{{ ucfirst(\App\Membership\Benefits::join([\App\Support\Features::enabled('wallet.spend') ? 'spend them '.\App\Membership\Benefits::join([\App\Support\Features::enabled('shop') ? 'in the shop' : null, \App\Support\Features::enabled('membership') ? 'on your membership' : null], 'or') : null, \App\Support\Features::enabled('wallet.cashout') ? 'cash out from '.\App\Support\Money::format(\App\Support\Settings::int('wallet.cashout_min')).' to Telebirr or bank' : null], 'or') ?: 'They are kept in your account') }}.</p></div>
        <div class="card text-sm"><p class="font-semibold">Your privacy</p><p class="muted mt-1">You see first names and initials of your direct invites, and only counts for levels 2 and 3. Never phones, addresses or purchases.</p></div>
    </div>
    @feature('top_referrers')
        @if (!empty($top))
            <h2 class="mt-10 text-2xl">This month's top referrers</h2>
            <ol class="mt-3 grid gap-2">@foreach ($top as $t)<li class="card flex justify-between py-3"><span>{{ $t['rank'] }}. {{ $t['name'] }} @if ($t['badge'])<span class="ms-2">@include('store.partials.badge-chip', ['b' => $t['badge']])</span>@endif</span><span class="muted">{{ $t['count'] }} new members</span></li>@endforeach</ol>
        @endif
    @endfeature
    <div class="mt-10 flex gap-3"><a href="{{ url(\App\Support\Features::enabled('accounts.signup') ? '/register' : '/login') }}" class="btn-primary">Join and get your link</a><a href="{{ url('/p/referral-rules') }}" class="btn-ghost">Full rules</a></div>
</section>
@endsection
