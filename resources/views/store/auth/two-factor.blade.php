@extends('layouts.store', ['title' => 'Two-step sign-in'])
@section('content')
<div class="container-km max-w-md py-12">
    <h1 class="text-4xl">One more step</h1>
    <p class="muted mt-2">Open your authenticator app and type the 6-digit code for {{ config('app.name') }}. Lost your phone? Use one of your recovery codes.</p>
    <form method="post" action="{{ url('/login/two-factor') }}" class="card mt-6 grid gap-4">
        @csrf
        <div><label class="label" for="c">Code</label><input id="c" name="code" class="input" required autofocus autocomplete="one-time-code" inputmode="text" maxlength="32"></div>
        @error('code')<p class="text-sm text-danger-600" role="alert">{{ $message }}</p>@enderror
        <button class="btn-primary">Sign in</button>
        <p class="muted text-sm"><a class="underline" href="{{ url('/login') }}">Start again</a></p>
    </form>
</div>
@endsection
