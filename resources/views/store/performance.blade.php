@extends('layouts.store', ['title' => 'Signal results', 'description' => 'Every closed signal and its result.'])
@section('content')
<div class="container-km max-w-4xl py-12">
    <h1 class="text-4xl">Signal results{{ $pair ? ': '.$pair : '' }}</h1>
    <div class="mt-4">@include('partials.pair-filter', ['base' => '/signals/performance'])</div>
    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="card"><p class="text-2xl font-semibold">{{ $stats['closed'] }}</p><p class="muted text-sm">closed</p></div>
        <div class="card"><p class="text-2xl font-semibold text-leaf-600">{{ $stats['wins'] }}</p><p class="muted text-sm">wins</p></div>
        <div class="card"><p class="text-2xl font-semibold text-danger-600">{{ $stats['losses'] }}</p><p class="muted text-sm">losses</p></div>
        <div class="card"><p class="text-2xl font-semibold">{{ $stats['win_rate'] !== null ? $stats['win_rate'].'%' : '—' }}</p><p class="muted text-sm">win rate</p></div>
    </div>
    <section class="card mt-6" aria-labelledby="wr-h">
        <h2 id="wr-h" class="font-sans text-base font-semibold">Win rate by month</h2>
        <div class="mt-3">@include('partials.winrate-chart', ['months' => $months])</div>
    </section>
    <div class="card mt-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr><th class="py-2 text-start">Closed</th><th class="text-start">Pair</th><th class="text-start">Side</th><th class="text-start">Result</th></tr></thead>
            <tbody class="divide-y divide-mist-200 dark:divide-ink-700">
                @forelse ($signals as $s)
                    <tr><td class="py-2">{{ $s->closed_at?->timezone(config('app.timezone'))->format('d M Y') }}</td><td>{{ $s->pair }}</td><td>{{ strtoupper($s->direction) }}</td><td class="capitalize">{{ $s->result }} {{ $s->result_value }}</td></tr>
                @empty
                    <tr><td colspan="4" class="muted py-4">No closed signals yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6 rounded-2xl border border-gold-500/40 bg-gold-500/10 p-4 text-sm">{!! nl2br(e(\App\Support\Settings::string('signals.risk_notice'))) !!}</div>
</div>
@endsection
