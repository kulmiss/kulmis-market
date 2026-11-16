@extends('layouts.member', ['title' => 'Orders'])
@section('content')
<h1 class="font-serif text-3xl font-semibold">Orders</h1>
@if (isset($downloads) && $downloads->isNotEmpty())
<section class="m-card mt-5" aria-labelledby="dl-h">
    <h2 id="dl-h" class="font-semibold">My downloads</h2>
    <ul class="mt-3 grid gap-2">
        @foreach ($downloads as $g)
            <li class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span>{{ $g->file->title }} <span class="m-muted">· order {{ $g->order->number }} · {{ $g->max_downloads - $g->downloads }} left · until {{ $g->expires_at->timezone(config('app.timezone'))->format('d M') }}</span></span>
                <form method="post" action="{{ url('/account/downloads/'.$g->id) }}">@csrf<button class="m-btn min-h-10">{{ $g->file->kind === 'video' ? 'Watch' : 'Download' }}</button></form>
            </li>
        @endforeach
    </ul>
</section>
@endif
<section class="m-card mt-5">
    @include('member.partials.table', ['table' => $table])
    @if (! $table->page->total() && ! $table->state['q'] && ! $table->state['filters'])
        <p class="mt-2 text-sm"><a class="underline" href="{{ url('/shop') }}">Visit the shop</a></p>
    @endif
</section>
@endsection
