@php $fmt = fn ($v) => \App\Support\Money::format((int) $v); @endphp
@extends('layouts.member', ['title' => 'Orders'])
@section('content')
<h1 class="font-serif text-3xl font-semibold">Orders</h1>
<section class="m-card mt-5">
    @forelse ($orders as $o)
        <a href="{{ url('/track?number='.$o->number) }}" class="flex items-center justify-between gap-3 border-b py-3 text-sm last:border-0" style="border-color: var(--m-line)">
            <div><p class="font-medium">{{ $o->number }}</p><p class="text-xs m-muted">{{ $o->created_at->timezone(config('app.timezone'))->format('j M Y') }}</p></div>
            <div class="text-end"><b class="tabular-nums">{{ $fmt($o->total) }}</b><p class="text-xs capitalize m-muted">{{ str_replace('_', ' ', $o->status) }}</p></div>
        </a>
    @empty
        <p class="text-sm m-muted">No orders yet. <a class="underline" href="{{ url('/shop') }}">Visit the shop</a></p>
    @endforelse
</section>
@endsection
