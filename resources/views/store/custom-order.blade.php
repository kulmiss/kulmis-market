@extends('layouts.store', ['title' => 'Custom order', 'description' => 'Tell us what you would like made by hand. We send you a price.'])
@section('content')
<div class="container-km max-w-2xl py-10">
    <p class="badge">Made for you</p>
    <h1 class="mt-3 text-4xl">Ask for a custom piece</h1>
    <p class="muted mt-3 text-lg">Describe it, add photos if you like, and tell us your budget. We send you a price. To start, you pay a deposit ({{ \App\Support\Settings::int('custom.deposit_bps') / 100 }}%); the rest when it is ready, before we send it.</p>
    <form class="card mt-6 grid gap-4" data-custom-form novalidate>
        <label><span class="label">What would you like?</span><textarea name="description" class="input min-h-32" required minlength="15" maxlength="3000" placeholder="For example: a mesob basket, 60 cm, green and gold, for a wedding gift"></textarea></label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label><span class="label">Photos (up to 3, optional)</span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple class="input"></label>
            <label><span class="label">Budget (optional)</span><input name="budget" class="input" inputmode="decimal" placeholder="{{ \App\Support\Currency::config()['symbol'] }}"></label>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <label><span class="label">Your name</span><input name="name" class="input" required maxlength="80" autocomplete="name"></label>
            <label><span class="label">Phone</span><input name="phone" class="input" required maxlength="20" inputmode="tel" autocomplete="tel"></label>
            <label><span class="label">Email (optional)</span><input name="email" type="email" class="input" maxlength="160" autocomplete="email"></label>
            <label><span class="label">City</span><input name="city" class="input" required maxlength="80" autocomplete="address-level2"></label>
        </div>
        <label><span class="label">Address</span><input name="address" class="input" required maxlength="500" autocomplete="street-address"></label>
        <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true" aria-label="Leave this empty">
        <button class="btn-primary">Send my request</button>
        <p class="text-sm" data-form-message role="status" aria-live="polite"></p>
    </form>
</div>
@endsection
