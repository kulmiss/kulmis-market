@extends('layouts.store', ['title' => 'Create your account'])
@section('content')
<div class="container-km max-w-md py-12">
    <h1 class="text-4xl">Create your account</h1>
    <form method="post" action="{{ url('/register') }}" class="card mt-6 grid gap-4">
        @csrf
        <div class="grid grid-cols-2 gap-3">
            <div><label class="label" for="f">First name</label><input id="f" name="first_name" class="input" required autocomplete="given-name" value="{{ old('first_name') }}"></div>
            <div><label class="label" for="ln">Last name</label><input id="ln" name="last_name" class="input" required autocomplete="family-name" value="{{ old('last_name') }}"></div>
        </div>
        <div><label class="label" for="ph">Phone</label><input id="ph" name="phone" class="input" required inputmode="tel" placeholder="09…" autocomplete="tel" value="{{ old('phone') }}"></div>
        <div><label class="label" for="em">Email (optional)</label><input id="em" name="email" type="email" class="input" autocomplete="email" value="{{ old('email') }}"></div>
        <div class="grid grid-cols-2 gap-3">
            <div><label class="label" for="pw">Password</label><input id="pw" name="password" type="password" class="input" required minlength="8" autocomplete="new-password"></div>
            <div><label class="label" for="pw2">Repeat</label><input id="pw2" name="password_confirmation" type="password" class="input" required autocomplete="new-password"></div>
        </div>
        @feature('referral')<div><label class="label" for="rc">Referral code (optional)</label><input id="rc" name="referral_code" class="input uppercase" value="{{ old('referral_code', $code) }}"></div>@endfeature
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="terms" value="1" required class="mt-1 size-4"> <span>I agree to the <a class="underline" href="{{ url('/p/terms') }}">terms</a> and <a class="underline" href="{{ url('/p/privacy') }}">privacy policy</a>.</span></label>
        @if ($errors->any())<p class="text-sm text-danger-600" role="alert">{{ $errors->first() }}</p>@endif
        <button class="btn-primary">Create account</button>
        <p class="muted text-sm">Already have one? <a class="underline" href="{{ url('/login') }}">Sign in</a></p>
    </form>
</div>
@endsection
