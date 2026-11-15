@extends('layouts.store', ['title' => 'Gift cards', 'description' => 'Give a Kulmis Market gift card: they choose what they love.'])
@section('content')
<div class="container-km max-w-2xl py-10">
    <p class="badge">Gift cards</p>
    <h1 class="mt-3 text-4xl">Give a gift card</h1>
    <p class="muted mt-3 text-lg">They choose what they love. The code works at checkout until the money is used{{ \App\Support\Settings::int('giftcards.valid_days') ? ', for '.\App\Support\Settings::int('giftcards.valid_days').' days' : '' }}.</p>
    <form class="card mt-6 grid gap-4" data-giftcard-buy novalidate>
        <fieldset><legend class="label">Amount</legend>
            <div class="mt-1 grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ($amounts as $i => $a)
                    <label class="flex min-h-12 cursor-pointer items-center justify-center rounded-xl border border-mist-200 font-semibold has-[:checked]:border-brand-600 has-[:checked]:text-brand-600 dark:border-ink-700">
                        <input type="radio" name="amount" value="{{ $a }}" class="sr-only" @checked($i === 1 || count($amounts) === 1)>{{ \App\Support\Money::format($a) }}
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div class="grid gap-3 sm:grid-cols-2">
            <label><span class="label">For (name, optional)</span><input name="recipient_name" class="input" maxlength="80"></label>
            <label><span class="label">Their phone or email (optional)</span><input name="recipient_contact" class="input" maxlength="160" placeholder="Empty = we send it to you"></label>
        </div>
        <label><span class="label">Message (optional)</span><input name="message" class="input" maxlength="300"></label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label><span class="label">Your name</span><input name="buyer_name" class="input" required maxlength="80" autocomplete="given-name"></label>
            <label><span class="label">Your phone</span><input name="buyer_phone" class="input" required maxlength="20" inputmode="tel" autocomplete="tel"></label>
        </div>
        @if ($telebirr)<pre class="rounded-xl bg-mist-100 p-3 text-sm whitespace-pre-wrap dark:bg-ink-900">{{ $telebirr }}</pre>@endif
        @if ($bank)<pre class="rounded-xl bg-mist-100 p-3 text-sm whitespace-pre-wrap dark:bg-ink-900">{{ $bank }}</pre>@endif
        <div class="grid gap-3 sm:grid-cols-2">
            <label><span class="label">Paid with</span><select name="method" class="input"><option value="telebirr">Telebirr</option><option value="bank_transfer">Bank transfer</option></select></label>
            <label><span class="label">Transaction reference</span><input name="reference" class="input" maxlength="120"></label>
        </div>
        <label><span class="label">Receipt photo or PDF</span><input name="proof" type="file" accept="image/*,application/pdf" class="input" required></label>
        <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
        <button class="btn-primary">Buy the gift card</button>
        <p class="text-sm" data-form-message role="status" aria-live="polite"></p>
    </form>
</div>
@endsection
