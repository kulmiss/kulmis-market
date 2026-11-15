@extends('layouts.store', ['title' => 'Checkout'])
@section('content')
<div class="container-km grid max-w-5xl gap-8 py-10 md:grid-cols-5">
    <div class="md:col-span-3">
        <h1 class="text-4xl">Checkout</h1>
        @if (!$user && !$guestAllowed)
            <p class="card mt-6">Please <a class="underline" href="{{ url('/login') }}">sign in</a>@feature('accounts.signup') or <a class="underline" href="{{ url('/register') }}">create an account</a>@endfeature to order.</p>
        @else
            @if (!$user)<p class="muted mt-2 text-sm">No account needed. Members, <a class="underline" href="{{ url('/login') }}">sign in</a> for member prices.</p>@endif
            @if ($errors->any())<div class="mt-4 rounded-xl bg-danger-600/10 p-3 text-sm text-danger-700" role="alert">{{ $errors->first() }}</div>@endif
            <form class="mt-6 grid gap-4" method="post" action="{{ url('/checkout') }}" @if (!$user) data-guest-checkout @endif data-checkout-form>
                @if ($user) @csrf @endif
                <input type="hidden" name="checkout_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                @if ($user && \App\Support\Features::enabled('shop.saved_addresses'))
                    @php $saved = \App\Models\Address::query()->where('user_id', $user->id)->orderByDesc('is_default')->orderBy('id')->get(); @endphp
                    @if ($saved->isNotEmpty())
                        <div><label class="label" for="saved-address">Deliver to</label>
                            <select id="saved-address" class="input" data-saved-address>
                                @foreach ($saved as $a)
                                    <option value="{{ $a->id }}" data-fill="{{ json_encode(['first_name' => $a->first_name, 'last_name' => $a->last_name, 'phone' => $a->phone, 'city' => $a->city, 'address' => $a->address]) }}" @selected($a->is_default)>{{ $a->label }}: {{ $a->city }}, {{ \Illuminate\Support\Str::limit($a->address, 40) }}</option>
                                @endforeach
                                <option value="">A new address</option>
                            </select>
                        </div>
                    @endif
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="cf">First name</label><input id="cf" name="first_name" class="input" required autocomplete="given-name" value="{{ old('first_name', $user?->first_name) }}"></div>
                    <div><label class="label" for="cl">Last name</label><input id="cl" name="last_name" class="input" autocomplete="family-name" value="{{ old('last_name', $user?->last_name) }}"></div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="cp">Phone</label><input id="cp" name="phone" class="input" required inputmode="tel" placeholder="09…" autocomplete="tel" value="{{ old('phone', $user?->phone) }}"></div>
                    <div><label class="label" for="ce">Email (optional)</label><input id="ce" name="email" type="email" class="input" autocomplete="email" value="{{ old('email', $user?->email) }}"></div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label" for="cc">City</label><input id="cc" name="city" class="input" required list="cities" autocomplete="address-level2" value="{{ old('city') }}"><datalist id="cities" data-cities></datalist></div>
                    <div><label class="label" for="cz">Delivery zone</label><select id="cz" name="shipping_zone_id" class="input" data-zones><option value="">Pick by city</option></select></div>
                </div>
                <div><label class="label" for="ca">Address (area, street, landmark)</label><textarea id="ca" name="address" class="input" rows="2" required autocomplete="street-address">{{ old('address') }}</textarea></div>
                @php $extrasOn = \App\Orders\Extras::available(); $chosen = (array) (app(\App\Cart\CartManager::class)->find(request())?->extras ?? []); @endphp
                @if ($extrasOn)
                    <fieldset class="grid gap-1"><legend class="label">Extras (optional)</legend>
                        @foreach ($extrasOn as $k => $x)
                            <label class="flex min-h-12 items-center gap-3 text-sm"><input type="checkbox" class="size-5" value="{{ $k }}" data-extra @checked(in_array($k, $chosen, true))> {{ $x['label'] }} <span class="muted">+ {{ $x['hint'] }}</span></label>
                        @endforeach
                    </fieldset>
                @endif
                <div><label class="label" for="cn">Notes (optional)</label><input id="cn" name="notes" class="input" value="{{ old('notes') }}"></div>
                @if ($user && \App\Support\Features::enabled('shop.saved_addresses'))
                    <label class="flex min-h-12 items-center gap-2 text-sm"><input type="checkbox" name="save_address" value="1" class="size-5"> Save this address for next time</label>
                @endif
                @if (!$user)
                    <div><label class="label" for="cr">Referral code (optional — a link you opened is remembered)</label><input id="cr" name="referral_code" class="input uppercase" value="{{ old('referral_code') }}"></div>
                @endif
                @if ($user && \App\Support\Features::enabled('wallet.spend'))
                    @php($avail = \App\Ledger\Wallet::for($user)->available())
                    @if ($avail >= 100)<label class="flex items-center gap-2"><input type="checkbox" name="use_wallet" value="1" class="size-4"> Use my wallet ({{ \App\Support\Money::format($avail) }} available)</label>@endif
                @endif
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" required class="mt-1 size-4"> <span>I agree to the <a class="underline" href="{{ url('/p/terms') }}">terms</a> and <a class="underline" href="{{ url('/p/refund-shipping') }}">refund &amp; shipping policy</a>.</span></label>
                <button class="btn-primary">Place order</button>
                <p data-form-message class="text-sm text-danger-600" role="alert"></p>
            </form>
        @endif
    </div>
    <aside class="md:col-span-2"><div class="sticky top-24 grid gap-6"><div class="card grid gap-1 text-sm" data-checkout-summary><p class="muted">Loading your cart…</p></div><div data-checkout-suggestions></div></div></aside>
</div>
@endsection
