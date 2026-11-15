@extends('layouts.store', ['title' => 'Give a month of membership', 'description' => 'Pay one month of membership for someone you love.'])
@section('content')
<div class="container-km max-w-2xl py-10">
    <p class="badge">Gift 🎁</p>
    <h1 class="mt-3 text-4xl">Give a month of membership</h1>
    <p class="muted mt-3 text-lg">{{ \App\Support\Money::format(\App\Support\Settings::int('membership.price')) }} for one month: member prices, free delivery and the signals channel. They get a private link to start it.</p>
    <form class="card mt-6 grid gap-4" data-gift-form novalidate>
        <fieldset class="grid gap-3"><legend class="font-semibold">For</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                <label><span class="label">Their name</span><input name="receiver_name" class="input" required maxlength="80"></label>
                <label><span class="label">Their phone or email</span><input name="receiver_contact" class="input" required maxlength="160" placeholder="09… or name@example.com"></label>
            </div>
            <label><span class="label">A short message (optional)</span><input name="message" class="input" maxlength="300" placeholder="Happy Timket!"></label>
        </fieldset>
        <fieldset class="grid gap-3"><legend class="font-semibold">From</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                <label><span class="label">Your name</span><input name="giver_name" class="input" required maxlength="80" autocomplete="given-name"></label>
                <label><span class="label">Your phone</span><input name="giver_phone" class="input" required maxlength="20" autocomplete="tel" inputmode="tel"></label>
            </div>
            <label><span class="label">Your email (optional)</span><input name="giver_email" type="email" class="input" maxlength="160" autocomplete="email"></label>
        </fieldset>
        <fieldset class="grid gap-3"><legend class="font-semibold">Pay</legend>
            @if ($telebirr)<pre class="rounded-xl bg-mist-100 p-3 text-sm whitespace-pre-wrap dark:bg-ink-900">{{ $telebirr }}</pre>@endif
            @if ($bank)<pre class="rounded-xl bg-mist-100 p-3 text-sm whitespace-pre-wrap dark:bg-ink-900">{{ $bank }}</pre>@endif
            <div class="grid gap-3 sm:grid-cols-2">
                <label><span class="label">Paid with</span><select name="method" class="input"><option value="telebirr">Telebirr</option><option value="bank_transfer">Bank transfer</option></select></label>
                <label><span class="label">Transaction reference</span><input name="reference" class="input" maxlength="120"></label>
            </div>
            <label><span class="label">Receipt photo or PDF</span><input name="proof" type="file" accept="image/*,application/pdf" class="input" required></label>
        </fieldset>
        <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
        <button class="btn-primary">Send the gift</button>
        <p class="text-sm" data-form-message role="status" aria-live="polite"></p>
    </form>
</div>
@endsection
