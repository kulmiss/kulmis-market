@extends('layouts.store', ['title' => 'Your cart'])
@section('content')
<div class="container-km max-w-3xl py-10" data-cart-page>
    <h1 class="text-4xl">Your cart</h1>
    <div data-cart-body class="mt-6"><p class="muted">Loading…</p></div>
</div>
<template id="cart-tpl">
    <div class="grid gap-3" data-lines></div>
    <form class="mt-6 flex gap-2" data-coupon-form>
        <label class="sr-only" for="coupon">Coupon</label>
        <input id="coupon" name="code" class="input" placeholder="Coupon code">
        <button class="btn-ghost">Apply</button>
    </form>
    <div class="card mt-6 grid gap-1 text-sm" data-totals></div>
    <div class="mt-4 hidden rounded-2xl bg-brand-500/10 p-4 text-sm" data-join-save></div>
    <div class="mt-6 flex justify-end"><a href="{{ url('/checkout') }}" class="btn-primary">Checkout</a></div>
</template>
@endsection
