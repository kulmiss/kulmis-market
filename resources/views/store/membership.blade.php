@php
    $i = fn ($k) => \App\Support\Settings::int($k);
    $m = fn ($k) => \App\Support\Money::format(\App\Support\Settings::int($k));
    $pct = fn ($k) => rtrim(rtrim(number_format(\App\Support\Settings::int($k) / 100, 2), '0'), '.').'%';
@endphp
@extends('layouts.store', ['title' => 'Membership', 'description' => 'Member prices, free shipping, early access, trading signals and referral earnings — '.$m('membership.price').' a month.'])
@section('content')
<section class="container-km py-12 text-center">
    <p class="badge">Kulmis membership</p>
    <h1 class="mx-auto mt-4 max-w-2xl text-4xl md:text-5xl">Everything we make, at member prices — plus signals and earnings.</h1>
    <p class="mt-5 text-3xl font-semibold">{{ $m('membership.price') }} <span class="muted text-lg font-normal">/ month</span></p>
    <p class="muted mt-1">Monthly only. Cancel any time by not renewing. {{ $i('membership.grace_days') }}-day grace period.</p>
    <div class="mt-7 flex justify-center gap-3">
        <a href="{{ url('/register') }}" class="btn-primary">Join now</a>
        <a href="{{ url('/login') }}" class="btn-ghost">I already have an account</a>
    </div>
</section>
@foreach ($banners as $b)<div class="container-km"><p class="card">{{ $b->title }} {{ $b->text }}</p></div>@endforeach
<section class="container-km grid gap-4 md:grid-cols-3">
    @feature('membership.member_discount')<div class="card"><h2 class="text-xl">{{ $pct('pricing.member_discount_bps') }} off everything</h2><p class="muted mt-2">Every product, every time. You see the member price next to the normal price.</p></div>@endfeature
    @feature('shop.free_shipping')<div class="card"><h2 class="text-xl">Free shipping over {{ $m('shipping.member_free_over') }}</h2><p class="muted mt-2">Anywhere we deliver in Ethiopia.</p></div>@endfeature
    @feature('shop.early_access')<div class="card"><h2 class="text-xl">{{ $i('catalog.early_access_hours') }}-hour early access</h2><p class="muted mt-2">See and buy new pieces before everyone else. Plus a members-only product every month.</p></div>@endfeature
    @feature('signals')<div class="card"><h2 class="text-xl">Trading signals included</h2><p class="muted mt-2">Normally {{ $m('signals.guest_price') }}/month — free for members, in a private Telegram channel.</p></div>@endfeature
    @feature('referral')<div class="card"><h2 class="text-xl">Earn from referrals</h2><p class="muted mt-2">Share your link. Earn on memberships, signals and shop orders from people you invite. <a class="text-clay-600 underline" href="{{ url('/referral-program') }}">How it works</a></p></div>@endfeature
    @feature('loyalty_reward')<div class="card"><h2 class="text-xl">{{ $m('loyalty.reward') }} thank-you</h2><p class="muted mt-2">Added to your wallet after every {{ $i('loyalty.every_months') }} months in a row.</p></div>@endfeature
</section>
<section class="container-km mt-12 max-w-3xl">
    <h2 class="text-2xl">Questions</h2>
    <details class="card mt-3"><summary class="cursor-pointer font-semibold">How do I pay?</summary><p class="muted mt-2">With Telebirr, CBE Birr, M-Pesa or card through Chapa, by bank/Telebirr transfer with a receipt photo, or from your wallet earnings.</p></details>
    <details class="card mt-3"><summary class="cursor-pointer font-semibold">What happens if I don't renew?</summary><p class="muted mt-2">We remind you 3 days before, on the day and after. You keep your benefits for {{ $i('membership.grace_days') }} more days. After that discounts, signals and earnings pause — your wallet balance stays yours.</p></details>
    <details class="card mt-3"><summary class="cursor-pointer font-semibold">Can I cash out my earnings?</summary><p class="muted mt-2">Yes — from {{ $m('wallet.cashout_min') }} to Telebirr or your bank, any time. We pay within {{ $i('wallet.payout_days') }} days.</p></details>
    <p class="muted mt-6 text-sm">See the <a class="underline" href="{{ url('/p/membership-terms') }}">membership terms</a>.</p>
</section>
@endsection
