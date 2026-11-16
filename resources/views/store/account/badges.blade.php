@php
    $groups = [
        'Team' => ['active_level1', 'total_team'],
        'Earnings & sales' => ['earnings_total', 'link_sales'],
        'Loyalty' => ['loyalty_months'],
        'Shopping' => ['orders_count'],
        'Special' => ['top_referrer_month', 'early_member'],
    ];
    $rows = collect($badges);
@endphp
@extends('layouts.member', ['title' => 'My badges'])
@section('content')
<div>
    <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-serif text-3xl font-semibold">My badges</h1>
            <p class="muted mt-1">{{ $summary['unlocked'] }} of {{ $summary['total'] }} unlocked</p>
        </div>
        @if ($summary['highest'])<div class="text-sm">Highest: @include('store.partials.badge-chip', ['b' => $summary['highest'], 'size' => 16])</div>@endif
    </div>
    @if ($summary['next'])
        <div class="card mt-6">
            <p class="text-sm font-semibold uppercase tracking-wide text-brand-600">Next badge</p>
            <div class="mt-3">@include('store.account.partials.badge-progress', ['b' => $summary['next']])</div>
        </div>
    @endif
    @foreach ($groups as $title => $rules)
        @php($items = $rows->whereIn('rule', $rules)->sortBy([['rule', 'asc'], ['threshold', 'asc']]))
        @if ($items->isNotEmpty())
            {{-- Phones: each group folded with a count (remembered); desktop: open. --}}
            <details class="mt-6 sm:mt-8" data-collapse="badges-{{ \Illuminate\Support\Str::slug($title) }}">
                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 sm:pointer-events-none">
                    <h2 class="text-2xl">{{ $title }}</h2>
                    <span class="rounded-full px-2.5 py-0.5 text-sm font-semibold m-soft">{{ $items->where('unlocked', true)->count() }}/{{ $items->count() }}</span>
                </summary>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($items as $b)
                        <div class="card {{ $b['unlocked'] ? '' : 'opacity-90' }}">@include('store.account.partials.badge-progress', ['b' => $b])
                            <p class="muted mt-2 text-xs">{{ $b['description'] }}</p></div>
                    @endforeach
                </div>
            </details>
        @endif
    @endforeach
</div>
@include('store.account.partials.badge-celebration', ['unseen' => $summary['unseen']])
@endsection
