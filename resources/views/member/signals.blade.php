@php use App\Badges\BadgeIcons as I; @endphp
@extends('layouts.member', ['title' => 'Signals'])
@section('content')
<h1 class="font-serif text-3xl font-semibold">Trading signals</h1>
<section class="m-card mt-5">
    @if ($signalsLink)
        <p class="text-sm m-muted">Your membership includes our private Telegram channel. Open your personal link in Telegram. It works for your Telegram account only, so don't share it.</p>
        <a class="m-btn mt-3" href="{{ $signalsLink }}" rel="noopener">{!! I::svg('send', 17) !!}Open in Telegram</a>
    @elseif ($isMember)
        <p class="text-sm m-muted">Included in your membership. Your personal Telegram link appears here as soon as our Telegram bot is connected.</p>
    @else
        <p class="text-sm m-muted">Become a member to get the private signals channel.</p>
        <a class="m-btn mt-3" href="{{ url('/account#membership') }}">Join</a>
    @endif
</section>
<section class="m-card mt-3">
    <h2 class="font-semibold">Recent results</h2>
    <div class="mt-3">@include('member.partials.table', ['table' => $table])</div>
</section>
@endsection
