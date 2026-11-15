@php $d = fn ($c) => $c ? \Illuminate\Support\Carbon::parse($c)->timezone(config('app.timezone'))->format('d M Y, H:i') : '—'; @endphp
@extends('layouts.member', ['title' => 'Security'])
@section('content')
<div class="max-w-3xl">
    <h1 class="font-serif text-3xl font-semibold">Security</h1>
    <p class="muted mt-1">Keep your account, your wallet and your earnings safe.</p>

    <section class="card mt-6" id="password">
        <h2 class="text-2xl">Password</h2>
        <p class="muted mt-1 text-sm">At least {{ $minLength }} characters. A short sentence you'll remember works well. Common passwords and your phone, email or name are refused. Changing it signs out your other devices.</p>
        <form method="post" action="{{ url('/account/security/password') }}" class="mt-4 grid gap-3 sm:grid-cols-2">
            @csrf
            <div class="sm:col-span-2"><label class="label" for="cp">Current password</label><input id="cp" name="current_password" type="password" class="input" required autocomplete="current-password"></div>
            <div><label class="label" for="np">New password</label><input id="np" name="password" type="password" class="input" required minlength="{{ $minLength }}" autocomplete="new-password"></div>
            <div><label class="label" for="np2">New password again</label><input id="np2" name="password_confirmation" type="password" class="input" required autocomplete="new-password"></div>
            <div class="sm:col-span-2"><button class="btn-primary">Change password</button></div>
        </form>
    </section>

    <section class="card mt-6" id="email">
        <h2 class="text-2xl">Email</h2>
        <p class="muted mt-1 text-sm">Now: <b>{{ $user->email ?: 'none' }}</b>@if ($user->pending_email) · waiting for you to confirm <b>{{ $user->pending_email }}</b> (check that inbox)@endif. We send a link to the new address; your email only changes when you open it.</p>
        <form method="post" action="{{ url('/account/security/email') }}" class="mt-4 grid gap-3 sm:grid-cols-2">
            @csrf
            <div><label class="label" for="ne">New email</label><input id="ne" name="email" type="email" class="input" required autocomplete="email"></div>
            <div><label class="label" for="ecp">Current password</label><input id="ecp" name="current_password" type="password" class="input" required autocomplete="current-password"></div>
            <div class="sm:col-span-2"><button class="btn-ghost">Send confirmation link</button></div>
        </form>
    </section>

    <section class="card mt-6" id="two-factor">
        <h2 class="text-2xl">Two-step sign-in</h2>
        @if ($recoveryCodes)
            <div class="mt-3 rounded-xl bg-sand-100 p-4 dark:bg-coffee-900">
                <p class="font-semibold">Save these recovery codes now</p>
                <p class="muted text-sm">Each works once if you lose your phone. We won't show them again.</p>
                <pre class="mt-2 font-mono text-sm leading-7">{{ implode("\n", $recoveryCodes) }}</pre>
            </div>
        @endif
        @if ($user->hasTwoFactor())
            <p class="mt-1 text-sm"><span class="badge-gold">On</span> Signing in needs your password and a code from your authenticator app.</p>
            <form method="post" action="{{ url('/account/security/two-factor/disable') }}" class="mt-4 grid gap-3 sm:grid-cols-3">
                @csrf
                <div><label class="label" for="dcp">Password</label><input id="dcp" name="current_password" type="password" class="input" required autocomplete="current-password"></div>
                <div><label class="label" for="dc">Code from the app</label><input id="dc" name="code" class="input" required autocomplete="one-time-code"></div>
                <div class="flex items-end"><button class="btn-ghost w-full">Turn off</button></div>
            </form>
        @elseif ($setup)
            <p class="muted mt-1 text-sm">1. Scan this with Google Authenticator, Microsoft Authenticator or similar. 2. Type the 6-digit code it shows.</p>
            <div class="mt-4 grid items-center gap-4 sm:grid-cols-[12rem_1fr]">
                <div class="rounded-xl bg-white p-2 [&_svg]:h-auto [&_svg]:w-full" aria-label="QR code">{!! $setup['qr_svg'] !!}</div>
                <div>
                    <p class="muted text-xs">Can't scan? Enter this key in the app: <span class="break-all font-mono">{{ $setup['secret'] }}</span></p>
                    <form method="post" action="{{ url('/account/security/two-factor/confirm') }}" class="mt-3 flex gap-2">
                        @csrf
                        <input name="code" class="input" required inputmode="numeric" autocomplete="one-time-code" placeholder="123456" aria-label="Code">
                        <button class="btn-primary">Turn on</button>
                    </form>
                </div>
            </div>
        @else
            <p class="muted mt-1 text-sm">Optional, and strongly recommended if you have earnings in your wallet. Even with your password, nobody can sign in without your phone.</p>
            <form method="post" action="{{ url('/account/security/two-factor') }}" class="mt-4">@csrf<button class="btn-primary">Set up two-step sign-in</button></form>
        @endif
    </section>

    <section class="card mt-6" id="devices">
        <h2 class="text-2xl">Where you're signed in</h2>
        <ul class="mt-3 divide-y divide-sand-200 text-sm dark:divide-coffee-800">
            @forelse ($sessions as $s)
                <li class="flex justify-between gap-3 py-2"><span>{{ $s['label'] }} @if ($s['current'])<b class="text-clay-600">(this device)</b>@endif</span><span class="muted">{{ $d($s['last_active']) }}</span></li>
            @empty
                <li class="py-2 muted">Only this device.</li>
            @endforelse
        </ul>
        @if ($devices->isNotEmpty())
            <p class="muted mt-4 text-xs">Browsers used before: {{ $devices->pluck('label')->unique()->implode(', ') }}.</p>
        @endif
        <form method="post" action="{{ url('/account/security/sign-out-everywhere') }}" class="mt-4">@csrf<button class="btn-ghost">Sign out all other devices and apps</button></form>
    </section>
</div>
@endsection
