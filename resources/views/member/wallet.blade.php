@php
    $fmt = fn ($v) => \App\Support\Money::format((int) $v);
    $d = fn ($c) => $c?->timezone(config('app.timezone'))->format('d M Y');
@endphp
@extends('layouts.member', ['title' => 'Wallet'])
@section('content')
<h1 class="font-serif text-3xl font-semibold">Wallet</h1>
<section class="mt-5 grid grid-cols-3 gap-3" id="wallet">
    @include('member.partials.stat', ['label' => 'Pending', 'value' => $fmt($wallet['pending']), 'icon' => 'clock', 'sub' => \App\Support\Settings::int('referral.pending_days').'-day refund window'])
    @include('member.partials.stat', ['label' => 'Available', 'value' => $fmt($wallet['available']), 'icon' => 'wallet', 'tone' => 'accent'])
    @include('member.partials.stat', ['label' => 'Cash-out requested', 'value' => $fmt($wallet['payout']), 'icon' => 'banknote'])
</section>
<section class="m-card mt-3">
            @feature('wallet.cashout')
            @php $acct = $payoutAccount; @endphp
            <div class="mt-5 rounded-xl border p-4" style="border-color: var(--m-line)">
                <p class="text-sm font-semibold">Cash-out account</p>
                @if ($acct)
                    <p class="mt-1 text-sm">{{ ucfirst($acct->method) }} {{ $acct->masked() }} · {{ $acct->account_name }}@if ($acct->bank_name) · {{ $acct->bank_name }}@endif</p>
                    @unless ($acct->isUsable())<p class="mt-1 text-xs text-amber-700 dark:text-amber-300">For your safety, you can cash out to this account from {{ $acct->usable_after->timezone(config('app.timezone'))->format('d M H:i') }}.</p>@endunless
                @else
                    <p class="muted mt-1 text-sm">Add the Telebirr number or bank account we should pay you to. The name must match yours.</p>
                @endif
                <details class="mt-2 text-sm" @if (!$acct) open @endif><summary class="cursor-pointer">{{ $acct ? 'Change account' : 'Add account' }}</summary>
                    <form method="post" action="{{ url('/account/payout-account') }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                        @csrf
                        <div><label class="label" for="pm">Type</label><select id="pm" name="method" class="m-input"><option value="telebirr">Telebirr</option><option value="bank">Bank account</option></select></div>
                        <div><label class="label" for="pn">Account name (must match {{ $user->name }})</label><input id="pn" name="account_name" class="m-input" required value="{{ $user->name }}"></div>
                        <div><label class="label" for="pnum">Phone / account number</label><input id="pnum" name="account_number" class="m-input" required inputmode="numeric"></div>
                        <div><label class="label" for="pb">Bank name (for bank)</label><input id="pb" name="bank_name" class="m-input"></div>
                        <div><label class="label" for="ppw">Your password</label><input id="ppw" name="current_password" type="password" class="m-input" required autocomplete="current-password"></div>
                        @if ($user->hasTwoFactor())<div><label class="label" for="pcode">Code from your app</label><input id="pcode" name="code" class="m-input" required autocomplete="one-time-code"></div>@endif
                        <p class="muted text-xs sm:col-span-2">We'll message you about this change. A new account can be used after {{ \App\Support\Settings::int('wallet.payout_account_hold_hours') }} hours.</p>
                        <button class="m-btn-ghost sm:col-span-2">Save account</button>
                    </form>
                </details>
            </div>
            @if ($acct?->isUsable())
            <form method="post" action="{{ url('/account/cash-out') }}" class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::random(32) }}">
                <div><label class="label" for="ca">Cash out (from {{ $fmt(\App\Support\Settings::int('wallet.cashout_min')) }})</label><input id="ca" name="amount_birr" class="m-input" inputmode="decimal" required placeholder="{{ \App\Support\Currency::config()['symbol'] }}"></div>
                <button class="m-btn">Request cash-out</button>
                <p class="muted w-full text-xs">Paid to {{ ucfirst($acct->method) }} {{ $acct->masked() }} within {{ \App\Support\Settings::int('wallet.payout_days') }} days.</p>
            </form>
            @endif
            @endfeature
            @if ($cashouts->isNotEmpty())
                <ul class="mt-4 grid gap-1 text-sm">@foreach ($cashouts as $c)<li class="flex justify-between"><span>{{ $d($c->created_at) }} · {{ $fmt($c->amount) }} to {{ $c->method }} {{ $c->maskedAccount() }}</span><span class="capitalize">{{ $c->status === 'on_hold' ? 'requested' : $c->status }}@if ($c->payout_reference) · ref {{ $c->payout_reference }}@endif</span></li>@endforeach</ul>
            @endif
</section>
<section class="m-card mt-3">
    <h2 class="font-semibold">Cash-outs</h2>
    @forelse ($cashouts as $c)
        <div class="mt-3 flex items-center justify-between gap-3 text-sm">
            <div><p>{{ $fmt($c->amount) }} to {{ $c->method }} {{ $c->maskedAccount() }}</p><p class="text-xs m-muted">{{ $d($c->created_at) }}@if ($c->payout_reference) · ref {{ $c->payout_reference }}@endif</p></div>
            <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', 'm-soft m-accent-text' => $c->status === 'paid', 'bg-red-500/10 text-red-600' => $c->status === 'rejected', 'bg-black/5 dark:bg-white/10' => ! in_array($c->status, ['paid', 'rejected'], true)])>{{ $c->status === 'on_hold' ? 'requested' : $c->status }}</span>
        </div>
        @if ($c->status === 'rejected' && $c->reject_reason)<p class="mt-1 text-xs m-muted">{{ $c->reject_reason }}</p>@endif
    @empty
        <p class="mt-3 text-sm m-muted">No cash-outs yet. Earnings become available {{ \App\Support\Settings::int('referral.pending_days') }} days after a payment.</p>
    @endforelse
</section>
@endsection
