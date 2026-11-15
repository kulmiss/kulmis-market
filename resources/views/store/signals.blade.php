@php($m = fn ($k) => \App\Support\Money::format(\App\Support\Settings::int($k)))
@extends('layouts.store', ['title' => 'Trading signals', 'description' => 'One plan, all signals, in a private Telegram channel. Free for members.'])
@section('content')
<section class="container-km grid gap-10 py-12 md:grid-cols-2">
    <div>
        <p class="badge">Private Telegram channel</p>
        <h1 class="mt-4 text-4xl">Trading signals — one plan, every signal.</h1>
        <p class="muted mt-4 text-lg">Pair, direction, entry, targets and stop loss, posted as they happen. Results are logged and locked.</p>
        <div class="mt-6 grid gap-3 sm:grid-cols-2">
            <div class="card"><p class="text-2xl font-semibold">{{ $m('signals.guest_price') }}</p><p class="muted text-sm">per month without membership</p></div>
            @feature('membership')<div class="card border-brand-500"><p class="text-2xl font-semibold text-brand-600">Included</p><p class="muted text-sm">for members ({{ $m('membership.price') }}/month, with all member benefits)</p></div>@endfeature
        </div>
        @feature('signals.performance_page')
            @if ($stats['closed'] > 0)
                <p class="mt-6"><a href="{{ url('/signals/performance') }}" class="font-semibold text-brand-600">See every result →</a> <span class="muted">{{ $stats['closed'] }} closed signals @if ($stats['win_rate'] !== null) · {{ $stats['win_rate'] }}% wins @endif</span></p>
            @endif
        @endfeature
        <div class="mt-8 rounded-2xl border border-gold-500/40 bg-gold-500/10 p-4 text-sm">{!! nl2br(e(\App\Support\Settings::string('signals.risk_notice'))) !!}</div>
    </div>
    <div class="card">
        <h2 class="text-2xl">Get access</h2>
        @feature('membership')<p class="muted mt-1 text-sm">Members: signals are already included — <a class="underline" href="{{ url('/account') }}">open your account</a> for your personal Telegram link.</p>@endfeature
        <form class="mt-5 grid gap-3" data-signals-form>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="sf">First name</label><input id="sf" name="first_name" class="input" required autocomplete="given-name"></div>
                <div><label class="label" for="sl">Last name</label><input id="sl" name="last_name" class="input" autocomplete="family-name"></div>
            </div>
            <div><label class="label" for="sp">Phone (your link comes by SMS)</label><input id="sp" name="phone" class="input" required inputmode="tel" placeholder="09…" autocomplete="tel"></div>
            <div><label class="label" for="se">Email (optional)</label><input id="se" name="email" type="email" class="input" autocomplete="email"></div>
            @feature('payments.manual')
            <div class="grid gap-3 rounded-xl bg-mist-100 p-3 text-sm sm:grid-cols-2 dark:bg-ink-900">
                <pre class="whitespace-pre-wrap font-sans">{{ \App\Support\Settings::string('payments.telebirr_details') }}</pre>
                <pre class="whitespace-pre-wrap font-sans">{{ \App\Support\Settings::string('payments.bank_details') }}</pre>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="sm">Paid with</label><select id="sm" name="method" class="input"><option value="telebirr">Telebirr</option><option value="bank_transfer">Bank transfer</option></select></div>
                <div><label class="label" for="sr">Transaction reference</label><input id="sr" name="reference" class="input"></div>
            </div>
            <div><label class="label" for="spf">Receipt photo or PDF</label><input id="spf" name="proof" type="file" accept="image/*,application/pdf" class="input" required></div>
            <button class="btn-primary">Send receipt · {{ $m('signals.guest_price') }}</button>
            @endfeature
            <p class="muted text-xs">Transfer {{ $m('signals.guest_price') }} by Telebirr or bank and send the receipt. Once we check it you get a personal one-time Telegram link by SMS. Access ends automatically after the month (+{{ \App\Support\Settings::int('signals.grace_days') }} days grace) unless you renew.</p>
            <p data-form-message class="text-sm text-danger-600" role="alert"></p>
        </form>
    </div>
</section>
@endsection
