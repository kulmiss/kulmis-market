@extends('layouts.store', ['title' => 'Reset password'])
@section('content')
<div class="container-km max-w-md py-12" data-forgot-page>
    <h1 class="text-4xl">Reset your password</h1>
    <form class="card mt-6 grid gap-3" data-forgot-form>
        <div><label class="label" for="fp">Your phone</label><input id="fp" name="phone" class="input" required inputmode="tel" placeholder="09…"></div>
        <button class="btn-dark">Send me a code by SMS</button>
    </form>
    <form class="card mt-4 grid gap-3" data-reset-form>
        <div><label class="label" for="rp">Phone</label><input id="rp" name="phone" class="input" required inputmode="tel"></div>
        <div><label class="label" for="rcode">6-digit code</label><input id="rcode" name="code" class="input" required inputmode="numeric" maxlength="6"></div>
        <div><label class="label" for="rpw">New password</label><input id="rpw" name="password" type="password" class="input" required minlength="8" autocomplete="new-password"></div>
        <button class="btn-primary">Set new password</button>
    </form>
    <p data-form-message class="mt-3 text-sm" role="status"></p>
</div>
@endsection
