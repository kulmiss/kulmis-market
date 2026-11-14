@extends('layouts.store', ['title' => 'Track your order'])
@section('content')
<div class="container-km max-w-2xl py-10" data-track-page>
    <h1 class="text-4xl">Track your order</h1>
    <form class="mt-6 grid gap-3 sm:grid-cols-3" data-track-form>
        <div class="sm:col-span-1"><label class="label" for="tn">Order number</label><input id="tn" name="number" class="input uppercase" required placeholder="KM…"></div>
        <div class="sm:col-span-1"><label class="label" for="tp">Phone</label><input id="tp" name="phone" class="input" required inputmode="tel" placeholder="09…"></div>
        <div class="flex items-end"><button class="btn-primary w-full">Track</button></div>
    </form>
    <div class="mt-8" data-track-result></div>
</div>
@endsection
