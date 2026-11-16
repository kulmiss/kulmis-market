@extends('layouts.store', ['title' => 'A gift for you'])
@section('content')
<div class="container-km max-w-xl py-14 text-center">
    <p class="text-5xl" aria-hidden="true">🎁</p>
    <h1 class="mt-4 text-3xl">{{ $gift->giver_name }} gave you a month of membership</h1>
    @if ($gift->message)<p class="mt-4 text-lg italic">“{{ $gift->message }}”</p>@endif
    @if ($gift->status === 'redeemed')
        <p class="muted mt-6">{{ $user && $gift->redeemed_by === $user->id ? 'This gift month is already on your account. Enjoy!' : 'This gift was already used.' }}</p>
        <a href="{{ url('/account') }}" class="btn-primary mt-6">Go to my account</a>
    @elseif ($gift->expires_at && $gift->expires_at->isPast())
        <p class="muted mt-6">This gift has expired. Please contact us and we will help.</p>
    @else
        <p class="muted mt-4">{{ ucfirst(\App\Membership\Benefits::sentence()) }}. Use it before {{ $gift->expires_at?->timezone(config('app.timezone'))->format('d M Y') }}.</p>
        @if ($errors->has('gift'))<p class="mt-4 text-sm text-danger-600" role="alert">{{ $errors->first('gift') }}</p>@endif
        @if ($user)
            <form method="post" action="{{ url('/gift/'.$gift->code) }}" class="mt-6">@csrf<button class="btn-primary">Start my month</button></form>
        @else
            <div class="mt-6 flex flex-wrap justify-center gap-3">
                @feature('accounts.signup')<a href="{{ url('/gift/'.$gift->code.'/start') }}" class="btn-primary">Create my account and start</a>@endfeature
                <a href="{{ url('/gift/'.$gift->code.'/start') }}" class="btn-ghost">I have an account</a>
            </div>
        @endif
    @endif
</div>
@endsection
