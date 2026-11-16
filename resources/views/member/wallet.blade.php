@php
    $fmt = fn ($v) => \App\Support\Money::format((int) $v);
    $d = fn ($c) => $c?->timezone(config('app.timezone'))->format('d M Y');
@endphp
@extends('layouts.member', ['title' => 'Wallet'])
@section('content')
<h1 class="font-serif text-3xl font-semibold">Wallet</h1>
<section class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3" id="wallet">
    @include('member.partials.stat', ['label' => 'On the way', 'value' => $fmt($wallet['pending']), 'icon' => 'clock', 'sub' => 'Ready in '.\App\Support\Settings::int('referral.pending_days').' days'])
    @include('member.partials.stat', ['label' => 'Ready to use', 'value' => $fmt($wallet['available']), 'icon' => 'wallet', 'tone' => 'accent'])
    @include('member.partials.stat', ['label' => \App\Member\MemberTables::buckets()[\App\Ledger\Wallet::PAYOUT], 'value' => $fmt($wallet['payout']), 'icon' => 'banknote'])
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
                <details class="mt-2 text-sm" @if (!$acct) open @endif><summary class="flex min-h-12 cursor-pointer items-center">{{ $acct ? 'Change account' : 'Add account' }}</summary>
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
                @feature('wallet.cashout_fee')
                    @php $fx = \App\Support\Settings::int('wallet.cashout_fee_fixed'); $fb = \App\Support\Settings::int('wallet.cashout_fee_bps'); @endphp
                    @if ($fx > 0 || $fb > 0)
                        <p class="basis-full text-xs m-muted">Cash-out fee: {{ trim(($fx > 0 ? $fmt($fx) : '').($fx > 0 && $fb > 0 ? ' + ' : '').($fb > 0 ? rtrim(rtrim(number_format($fb / 100, 2), '0'), '.').'%' : '')) }}. You get the amount minus the fee.</p>
                    @endif
                @endfeature
                <p class="muted w-full text-xs">Paid to {{ ucfirst($acct->method) }} {{ $acct->masked() }} within {{ \App\Support\Settings::int('wallet.payout_days') }} days.</p>
            </form>
            @endif
            @endfeature
</section>
<section class="m-card mt-3">
    <h2 class="font-semibold">Money history</h2>
    <p class="mt-1 text-xs m-muted">Every time money comes into or goes out of your wallet.</p>
    <div class="mt-3">@include('member.partials.table', ['table' => $moneyTable])</div>
</section>
@if (\App\Support\Features::enabled('wallet.cashout') || $cashoutTable->page->total())
<section class="m-card mt-3">
    <h2 class="font-semibold">{{ \App\Support\Features::enabled('wallet.cashout') ? 'Cash-outs' : 'Money sent out' }}</h2>
    <div class="mt-3">@include('member.partials.table', ['table' => $cashoutTable])</div>
    @if (! $cashoutTable->page->total())<p class="mt-2 text-xs m-muted">Earnings become ready to use {{ \App\Support\Settings::int('referral.pending_days') }} days after a payment.</p>@endif
</section>
@endif
@endsection
