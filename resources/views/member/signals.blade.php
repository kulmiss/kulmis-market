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
<section class="m-card mt-3" aria-labelledby="res-h">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="res-h" class="font-semibold">Results{{ $pair ? ': '.$pair : '' }}</h2>
        <p class="text-sm m-muted">{{ $totals['closed'] }} closed · {{ $totals['wins'] }} wins · {{ $totals['losses'] }} losses · win rate <b>{{ $totals['win_rate'] !== null ? $totals['win_rate'].'%' : '—' }}</b></p>
    </div>
    <div class="mt-3">@include('partials.pair-filter', ['base' => '/account/signals'])</div>
    <div class="mt-4">@include('partials.winrate-chart', ['months' => $months])</div>
</section>
@feature('account.notifications')
<section class="m-card mt-3" aria-labelledby="alert-h">
    <h2 id="alert-h" class="font-semibold">Signal notes</h2>
    <p class="mt-1 text-sm m-muted">Get a note in your bell when a new signal is posted. The signal itself is in the Telegram channel.</p>
    <form method="post" action="{{ url('/account/signals/alerts') }}" class="mt-3 grid gap-1">@csrf
        <input type="hidden" name="on" value="0">
        <label class="flex min-h-12 items-center gap-3 text-sm"><input type="checkbox" class="size-5" name="on" value="1" @checked(\App\Notify\NotifyPrefs::on($user, 'bell.signal'))> Tell me about new signals</label>
        @if (count($pairs))
            @php $mine = \App\Notify\NotifyPrefs::pairs($user); @endphp
            <fieldset class="mt-1"><legend class="text-sm font-medium">Only these pairs (none ticked = all pairs)</legend>
                <div class="mt-1 flex flex-wrap gap-x-4">
                    @foreach ($pairs as $p)
                        <label class="flex min-h-12 items-center gap-2 text-sm"><input type="checkbox" class="size-5" name="pairs[]" value="{{ $p }}" @checked(in_array($p, $mine, true))> {{ $p }}</label>
                    @endforeach
                </div>
            </fieldset>
        @endif
        <div><button class="m-btn mt-2">Save</button></div>
    </form>
</section>
@endfeature
<section class="m-card mt-3">
    <h2 class="font-semibold">Recent results</h2>
    <div class="mt-3">@include('member.partials.table', ['table' => $table])</div>
</section>
@endsection
