@extends('layouts.member', ['title' => \App\Support\Features::enabled('referral.contests') ? 'Contests' : 'Challenges'])
@section('content')
@php use App\Badges\BadgeIcons as I; use App\Support\Money; $tz = config('app.timezone'); @endphp
<h1 class="font-serif text-3xl font-semibold">{{ \App\Membership\Benefits::join([\App\Support\Features::enabled('referral.contests') ? 'Contests' : null, \App\Support\Features::enabled('referral.challenges') ? (\App\Support\Features::enabled('referral.contests') ? 'challenges' : 'Challenges') : null]) }}</h1>
<p class="mt-1 text-sm m-muted">Invite people, win prizes. Only real payments count; prizes are paid after the {{ \App\Support\Settings::int('referral.pending_days') }}-day check.</p>

@if (count($challenges))
<section class="m-card mt-5" aria-labelledby="ch-h">
    <h2 id="ch-h" class="font-semibold">This month's challenges</h2>
    <div class="mt-3 grid gap-4">
        @foreach ($challenges as $row)
            @php $c = $row['challenge']; @endphp
            <div>
                <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                    <span class="font-semibold">{{ $c->name }}</span>
                    <span class="m-muted">Bring {{ $c->target }} new paying {{ $c->target === 1 ? 'member' : 'members' }} → {{ Money::format($c->reward) }}</span>
                </div>
                <div class="mt-2 h-2.5 overflow-hidden rounded-full m-soft" role="progressbar" aria-label="{{ $c->name }}" aria-valuemin="0" aria-valuemax="{{ $c->target }}" aria-valuenow="{{ min($row['count'], $c->target) }}">
                    <div class="h-full rounded-full" style="width: {{ $row['percent'] }}%; background: var(--m-accent)"></div>
                </div>
                <p class="mt-1 text-xs m-muted">{{ $row['done'] ? 'Done! 🎉 Paid after the month and the check.' : $row['count'].' of '.$c->target.' so far this month.' }}</p>
            </div>
        @endforeach
    </div>
</section>
@endif

@forelse ($contests as $row)
    @php $c = $row['contest']; $phase = $row['phase']; @endphp
    <section class="m-card mt-4" aria-labelledby="c{{ $c->id }}">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 id="c{{ $c->id }}" class="text-lg font-semibold">{!! I::svg('trophy', 18) !!} {{ $c->name }}</h2>
                <p class="text-sm m-muted">{{ \App\Models\Contest::RULES[$c->rule] ?? $c->rule }}</p>
            </div>
            <span class="rounded-full px-2.5 py-1 text-xs font-semibold m-soft m-accent-text">
                @switch($phase)
                    @case('scheduled') Starts {{ $c->starts_at->timezone($tz)->format('d M') }} @break
                    @case('running') Ends in <span data-countdown="{{ $c->ends_at->toIso8601String() }}">{{ $c->ends_at->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span> @break
                    @case('checking') Prizes on {{ $c->settlesAt()->timezone($tz)->format('d M') }} @break
                    @default Finished
                @endswitch
            </span>
        </div>
        @if ($c->description)<p class="mt-2 text-sm">{{ $c->description }}</p>@endif
        <p class="mt-2 text-sm"><b>Prizes (up to):</b>
            @foreach ($c->prizes as $i => $p)<span class="whitespace-nowrap">{{ $i + 1 }}. {{ Money::format($p) }}</span>@if (! $loop->last) · @endif @endforeach
        </p>
        @if ($row['mine'])
            <p class="mt-2 rounded-xl px-3 py-2 text-sm m-soft">You: {{ $row['mine']['place'] ? 'place '.$row['mine']['place'] : 'not on the board yet' }} · {{ $c->rule === 'shop_sales' ? Money::format($row['mine']['score']) : $row['mine']['score'].' new paying' }}</p>
        @endif
        @if (count($row['board']))
            <ol class="mt-3 divide-y text-sm" style="border-color: var(--m-line)">
                @foreach ($row['board'] as $b)
                    <li class="flex items-center gap-3 py-2" style="border-color: var(--m-line)">
                        <span class="w-6 text-end font-bold tabular-nums">{{ $b['place'] }}</span>
                        <span class="flex-1 font-medium">{{ $b['name'] }}@if ($b['user_id'] === $user->id) <span class="m-muted">(you)</span>@endif</span>
                        @if ($b['badge'])<span class="inline-flex items-center gap-1 text-xs" style="color: {{ $b['badge']['color'] }}">{!! I::svg($b['badge']['icon'], 14) !!}{{ $b['badge']['name'] }}</span>@endif
                        <span class="tabular-nums m-muted">{{ $b['score_text'] }}</span>
                    </li>
                @endforeach
            </ol>
        @elseif ($phase === 'running')
            <p class="mt-3 text-sm m-muted">No one on the board yet. Be the first!</p>
        @endif
        @if ($phase === 'paid' && $c->result)
            <ol class="mt-3 grid gap-1 text-sm">
                @foreach ($c->result as $r)<li>{{ $r['place'] }}. {{ $r['name'] }} — {{ Money::format($r['prize']) }}</li>@endforeach
            </ol>
            @if ($c->was_cut)<p class="mt-1 text-xs m-muted">Prizes were shared out within the contest budget.</p>@endif
        @endif
    </section>
@empty
    @if (! count($challenges))
        <div class="m-card mt-5 p-8 text-center text-sm m-muted">No contest right now. We will tell you in your bell when the next one starts.</div>
    @endif
@endforelse
@endsection
