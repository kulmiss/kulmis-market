@extends('layouts.store', ['title' => 'Sign in'])
@section('content')
<div class="container-km max-w-md py-12">
    <h1 class="text-4xl">Welcome back</h1>
    <form method="post" action="{{ url('/login') }}" class="card mt-6 grid gap-4">
        @csrf
        <div><label class="label" for="l">Phone or email</label><input id="l" name="login" class="input" required autocomplete="username" value="{{ old('login') }}"></div>
        <div><label class="label" for="p">Password</label><input id="p" name="password" type="password" class="input" required autocomplete="current-password"></div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1" class="size-4"> Keep me signed in</label>
        @error('login')<p class="text-sm text-brand-600" role="alert">{{ $message }}</p>@enderror
        <button class="btn-primary">Sign in</button>
        <p class="muted text-sm">New here? <a class="underline" href="{{ url('/register') }}">Create an account</a> · <a class="underline" href="{{ url('/forgot') }}">Forgot password?</a></p>
    </form>
</div>
@endsection
