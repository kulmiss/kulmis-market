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
    @forelse ($signals as $s)
        <div class="mt-3 flex items-center justify-between text-sm">
            <span><b>{{ $s->pair }}</b> <span class="uppercase m-muted">{{ $s->direction }}</span> · {{ $s->posted_at?->timezone(config('app.timezone'))->format('j M') }}</span>
            <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', 'm-soft m-accent-text' => $s->result === 'win', 'bg-red-500/10 text-red-600' => $s->result === 'loss', 'bg-black/5 dark:bg-white/10' => ! in_array($s->result, ['win', 'loss'], true)])>{{ $s->result ?? 'open' }}@if ($s->result_value) · {{ $s->result_value }}@endif</span>
        </div>
    @empty
        <p class="mt-3 text-sm m-muted">No signals yet.</p>
    @endforelse
</section>
@endsection
