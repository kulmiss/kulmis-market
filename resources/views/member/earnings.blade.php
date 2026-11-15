@php $fmt = fn ($v) => \App\Support\Money::format((int) $v); @endphp
@extends('layouts.member', ['title' => 'Earnings'])
@section('content')
<h1 class="font-serif text-3xl font-semibold">Earnings</h1>
<section class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    @include('member.partials.stat', ['label' => 'Total earned', 'value' => $fmt($dash['money']['earned']), 'icon' => 'coins', 'tone' => 'accent'])
    @include('member.partials.stat', ['label' => 'This month', 'value' => $fmt($dash['money']['this_month']), 'icon' => 'trending-up', 'sub' => 'Last month '.$fmt($dash['money']['last_month'])])
    @include('member.partials.stat', ['label' => 'On the way', 'value' => $fmt($dash['money']['pending']), 'icon' => 'clock'])
    @include('member.partials.stat', ['label' => 'Ready to use', 'value' => $fmt($dash['money']['available']), 'icon' => 'wallet'])
</section>
<section class="m-card mt-3">
    <div class="flex items-center justify-between"><h2 class="font-semibold">Last 12 months</h2></div>
    @if (array_sum(array_column($monthly, 'value')) > 0)<div class="mt-3">@include('member.partials.chart', ['points' => $monthly])</div>
    @else<div class="mt-3 grid h-44 place-items-center rounded-xl text-sm m-muted m-soft">No earnings yet.</div>@endif
</section>
<section class="m-card mt-3">
    <h2 class="font-semibold">History</h2>
    <div class="mt-3">@include('member.partials.table', ['table' => $table])</div>
</section>
@endsection
