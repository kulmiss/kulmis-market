@php
    $fmt = fn ($v) => \App\Support\Money::format((int) $v);
    $d = fn ($c) => $c?->timezone(config('app.timezone'))->format('d M Y');
@endphp
@extends('layouts.store', ['title' => 'My account'])
@section('content')
<div class="container-km py-10">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><h1 class="text-4xl">Selam, {{ $user->first_name }}</h1>
            <p class="muted mt-1">@if ($isMember) Member · renews {{ $d($membership?->current_period_end) }}@if ($membership?->status === 'grace') · <b class="text-clay-600">grace period until {{ $d($membership->grace_ends_at) }}</b>@endif @else Not a member yet @endif</p></div>
        <div class="flex gap-2">@if ($badges)<a class="btn-ghost" href="{{ url('/account/badges') }}">Badges ({{ $badges['unlocked'] }})</a>@endif<a class="btn-ghost" href="{{ url('/account/security') }}">Security</a><form method="post" action="{{ url('/logout') }}">@csrf<button class="btn-ghost">Sign out</button></form></div>
    </div>
    @if ($errors->any())<div class="mt-4 rounded-xl bg-clay-500/10 p-3 text-sm text-clay-700" role="alert">{{ $errors->first() }}</div>@endif

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        @feature('membership')
        <section class="card lg:col-span-1" id="membership">
            <h2 class="text-2xl">Membership</h2>
            <p class="muted mt-1 text-sm">{{ $fmt(\App\Support\Settings::int('membership.price')) }} per month @if ($isMember) — renew any time; days are added on top.@endif</p>
            <form method="post" action="{{ url('/account/membership/pay') }}" class="mt-4 grid gap-2">
                @csrf
                @chapa<button name="method" value="chapa" class="btn-primary">{{ $isMember ? 'Renew' : 'Join' }} with Chapa</button>@elsefeature('payments.chapa')<p class="text-sm text-clay-700" role="status">{{ app(\App\Payments\ChapaGateway::class)->unsupportedMessage() }}</p>@endchapa
                @if (\App\Support\Features::enabled('wallet.spend') && $wallet['available'] >= 100)
                    @if ($wallet['available'] >= \App\Support\Settings::int('membership.price'))
                        <button name="method" value="wallet" class="btn-dark">Pay from wallet ({{ $fmt($wallet['available']) }})</button>
                    @elsechapa
                        <button name="method" value="wallet_chapa" class="btn-ghost">Wallet {{ $fmt($wallet['available']) }} + rest with Chapa</button>
                    @endif
                @endif
            </form>
            @feature('payments.manual')
            <details class="mt-3 text-sm"><summary class="cursor-pointer">Paid by bank or Telebirr transfer?</summary>
                <form method="post" action="{{ url('/account/membership/proof') }}" enctype="multipart/form-data" class="mt-3 grid gap-2">
                    @csrf
                    <select name="method" class="input" aria-label="How you paid"><option value="telebirr">Telebirr</option><option value="bank_transfer">Bank transfer</option></select>
                    <input name="reference" class="input" placeholder="Transaction reference">
                    <input name="proof" type="file" accept="image/*,application/pdf" class="input" required aria-label="Receipt photo or PDF">
                    <button class="btn-ghost">Send receipt</button>
                </form>
            </details>
            @endfeature
            @if ($signalsLink)
                <div class="mt-5 rounded-xl bg-sand-100 p-3 text-sm dark:bg-coffee-900"><p class="font-semibold">Your signals channel</p>
                    <p class="muted">Open your personal link in Telegram (don't share it):</p>
                    <a class="mt-1 block break-all text-clay-600 underline" href="{{ $signalsLink }}" rel="noopener">{{ $signalsLink }}</a></div>
            @endif
        </section>
        @endfeature

        @if ($referral)
        <section class="card lg:col-span-2" id="referral">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h2 class="text-2xl">Your referral link</h2>
                    @if (!$referral['earning'])<p class="mt-1 text-sm text-clay-600">Become an active member to earn from your team.</p>@endif
                    @if ($referral['under_review'])<p class="mt-1 text-sm text-clay-600">Some of your earnings are being checked by our team. They stay safe and will be released after the check.</p>@endif</div>
                @if ($badges && $badges['highest'])<a href="{{ url('/account/badges') }}">@include('store.partials.badge-chip', ['b' => $badges['highest'], 'size' => 16])</a>@endif
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto]">
                <div class="grid gap-2">
                    <div class="flex gap-2"><input class="input font-mono" readonly value="{{ $referral['link'] }}" data-copy-source aria-label="Referral link"><button type="button" class="btn-ghost" data-copy>Copy</button></div>
                    <p class="text-sm">Code: <b class="font-mono text-lg">{{ $referral['code'] }}</b></p>
                    <div class="flex flex-wrap gap-2">
                        <a class="btn-ghost" href="{{ $referral['share']['telegram'] }}" target="_blank" rel="noopener">Share on Telegram</a>
                        <a class="btn-ghost" href="{{ $referral['share']['whatsapp'] }}" target="_blank" rel="noopener">Share on WhatsApp</a>
                    </div>
                    @if ($badges && $badges['next'])<a href="{{ url('/account/badges') }}" class="mt-1 block rounded-xl border border-sand-200 p-3 dark:border-white/10"><span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-clay-600">Next badge</span>@include('store.account.partials.badge-progress', ['b' => $badges['next']])</a>@endif
                    @if ($referral['can_change_code'])
                        <form method="post" action="{{ url('/account/referral-code') }}" class="mt-2 flex gap-2">@csrf<input name="code" class="input uppercase" placeholder="Choose your code (once)" maxlength="12"><button class="btn-ghost">Save</button></form>
                    @endif
                </div>
                <img src="{{ url('/account/referral-qr.svg') }}" alt="QR code for your referral link" width="144" height="144" class="rounded-xl bg-white p-2" loading="lazy">
            </div>
            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-sand-100 p-3 dark:bg-coffee-900"><p class="muted text-xs">Level 1</p><p class="text-2xl font-semibold">{{ $referral['team']['level1_count'] }}</p><p class="muted text-xs">{{ $referral['team']['level1_active'] }} active · earned {{ $fmt($referral['earnings_by_level'][1]) }}</p></div>
                <div class="rounded-xl bg-sand-100 p-3 dark:bg-coffee-900"><p class="muted text-xs">Level 2</p><p class="text-2xl font-semibold">{{ $referral['team']['level2_count'] }}</p><p class="muted text-xs">earned {{ $fmt($referral['earnings_by_level'][2]) }}</p></div>
                <div class="rounded-xl bg-sand-100 p-3 dark:bg-coffee-900"><p class="muted text-xs">Level 3</p><p class="text-2xl font-semibold">{{ $referral['team']['level3_count'] }}</p><p class="muted text-xs">earned {{ $fmt($referral['earnings_by_level'][3]) }}</p></div>
            </div>
            @if (count($referral['team']['level1']))
                <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold">Your direct invites</summary>
                    <ul class="mt-2 grid gap-1 text-sm sm:grid-cols-2">@foreach ($referral['team']['level1'] as $p)<li class="flex justify-between rounded-lg bg-sand-100 px-3 py-2 dark:bg-coffee-900"><span>{{ $p['name'] }}</span><span class="{{ $p['active'] ? 'text-leaf-600' : 'muted' }}">{{ $p['active'] ? 'active' : 'not active' }} · {{ $p['joined'] }}</span></li>@endforeach</ul>
                </details>
            @endif
        </section>
        @endif

        @feature('wallet')
        <section class="card lg:col-span-2" id="wallet">
            <h2 class="text-2xl">Wallet</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div><p class="muted text-xs">Pending ({{ \App\Support\Settings::int('referral.pending_days') }}-day refund window)</p><p class="text-2xl font-semibold">{{ $fmt($wallet['pending']) }}</p></div>
                <div><p class="muted text-xs">Available</p><p class="text-2xl font-semibold text-leaf-600">{{ $fmt($wallet['available']) }}</p></div>
                <div><p class="muted text-xs">Cash-out requested</p><p class="text-2xl font-semibold">{{ $fmt($wallet['payout']) }}</p></div>
            </div>
            @feature('wallet.cashout')
            @php $acct = $payoutAccount; @endphp
            <div class="mt-5 rounded-xl border border-sand-200 p-4 dark:border-coffee-800">
                <p class="text-sm font-semibold">Cash-out account</p>
                @if ($acct)
                    <p class="mt-1 text-sm">{{ ucfirst($acct->method) }} {{ $acct->masked() }} · {{ $acct->account_name }}@if ($acct->bank_name) · {{ $acct->bank_name }}@endif</p>
                    @unless ($acct->isUsable())<p class="mt-1 text-xs text-clay-600">For your safety, you can cash out to this account from {{ $acct->usable_after->timezone(config('app.timezone'))->format('d M H:i') }}.</p>@endunless
                @else
                    <p class="muted mt-1 text-sm">Add the Telebirr number or bank account we should pay you to. The name must match yours.</p>
                @endif
                <details class="mt-2 text-sm" @if (!$acct) open @endif><summary class="cursor-pointer">{{ $acct ? 'Change account' : 'Add account' }}</summary>
                    <form method="post" action="{{ url('/account/payout-account') }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                        @csrf
                        <div><label class="label" for="pm">Type</label><select id="pm" name="method" class="input"><option value="telebirr">Telebirr</option><option value="bank">Bank account</option></select></div>
                        <div><label class="label" for="pn">Account name (must match {{ $user->name }})</label><input id="pn" name="account_name" class="input" required value="{{ $user->name }}"></div>
                        <div><label class="label" for="pnum">Phone / account number</label><input id="pnum" name="account_number" class="input" required inputmode="numeric"></div>
                        <div><label class="label" for="pb">Bank name (for bank)</label><input id="pb" name="bank_name" class="input"></div>
                        <div><label class="label" for="ppw">Your password</label><input id="ppw" name="current_password" type="password" class="input" required autocomplete="current-password"></div>
                        @if ($user->hasTwoFactor())<div><label class="label" for="pcode">Code from your app</label><input id="pcode" name="code" class="input" required autocomplete="one-time-code"></div>@endif
                        <p class="muted text-xs sm:col-span-2">We'll message you about this change. A new account can be used after {{ \App\Support\Settings::int('wallet.payout_account_hold_hours') }} hours.</p>
                        <button class="btn-ghost sm:col-span-2">Save account</button>
                    </form>
                </details>
            </div>
            @if ($acct?->isUsable())
            <form method="post" action="{{ url('/account/cash-out') }}" class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::random(32) }}">
                <div><label class="label" for="ca">Cash out (from {{ $fmt(\App\Support\Settings::int('wallet.cashout_min')) }})</label><input id="ca" name="amount_birr" class="input" inputmode="decimal" required placeholder="{{ \App\Support\Currency::config()['symbol'] }}"></div>
                <button class="btn-primary">Request cash-out</button>
                <p class="muted w-full text-xs">Paid to {{ ucfirst($acct->method) }} {{ $acct->masked() }} within {{ \App\Support\Settings::int('wallet.payout_days') }} days.</p>
            </form>
            @endif
            @endfeature
            @if ($cashouts->isNotEmpty())
                <ul class="mt-4 grid gap-1 text-sm">@foreach ($cashouts as $c)<li class="flex justify-between"><span>{{ $d($c->created_at) }} · {{ $fmt($c->amount) }} to {{ $c->method }} {{ $c->maskedAccount() }}</span><span class="capitalize">{{ $c->status === 'on_hold' ? 'requested' : $c->status }}@if ($c->payout_reference) · ref {{ $c->payout_reference }}@endif</span></li>@endforeach</ul>
            @endif
            @if ($history->isNotEmpty())
                <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold">Earnings history</summary>
                    <ul class="mt-2 grid gap-1 text-sm">@foreach ($history as $h)<li class="flex justify-between"><span class="capitalize">{{ $h['type'] }} · level {{ $h['level'] }}</span><span>{{ $fmt($h['amount'] - $h['reversed']) }} · {{ $h['status'] }}</span></li>@endforeach</ul>
                </details>
            @endif
        </section>
        @endfeature

        <section class="card">
            <h2 class="text-2xl">Orders</h2>
            @forelse ($orders as $o)
                <a href="{{ url('/track?number='.$o->number) }}" class="mt-2 flex justify-between text-sm hover:underline"><span>{{ $o->number }}</span><span class="capitalize">{{ str_replace('_', ' ', $o->status) }} · {{ $fmt($o->total) }}</span></a>
            @empty
                <p class="muted mt-2 text-sm">No orders yet. <a class="underline" href="{{ url('/shop') }}">Visit the shop</a></p>
            @endforelse
        </section>

        @if ($earlyAccess->isNotEmpty())
        <section class="lg:col-span-3">
            <h2 class="text-2xl">Early access for you</h2>
            <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-6">@foreach ($earlyAccess as $p)@include('store.partials.product-card', ['p' => $p])@endforeach</div>
        </section>
        @endif
    </div>
</div>
@if ($badges)@include('store.account.partials.badge-celebration', ['unseen' => $badges['unseen']])@endif
@endsection
