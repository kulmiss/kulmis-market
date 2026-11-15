@extends('layouts.member', ['title' => 'Orders'])
@section('content')
<h1 class="font-serif text-3xl font-semibold">Orders</h1>
<section class="m-card mt-5">
    @include('member.partials.table', ['table' => $table])
    @if (! $table->page->total() && ! $table->state['q'] && ! $table->state['filters'])
        <p class="mt-2 text-sm"><a class="underline" href="{{ url('/shop') }}">Visit the shop</a></p>
    @endif
</section>
@endsection
