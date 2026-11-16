@php use App\Badges\BadgeIcons as I; $t = $ref['team']; @endphp
@extends('layouts.member', ['title' => 'Team'])
@section('content')
<h1 class="font-serif text-3xl font-semibold">Your team</h1>
<p class="mt-1 text-sm m-muted">People you invited (level 1), the people they invited (level 2), and the next ones (level 3). To keep people's privacy, you see names only for the people you invited.</p>
<section class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    {{-- A level that is switched off is not shown (no earnings there). --}}
    @feature('referral.level1')@include('member.partials.stat', ['label' => 'Level 1', 'value' => $t['level1_count'], 'icon' => 'users', 'tone' => 'accent', 'sub' => $t['level1_active'].' active'])@endfeature
    @feature('referral.level2')@include('member.partials.stat', ['label' => 'Level 2', 'value' => $t['level2_count'], 'icon' => 'network'])@endfeature
    @feature('referral.level3')@include('member.partials.stat', ['label' => 'Level 3', 'value' => $t['level3_count'], 'icon' => 'network'])@endfeature
</section>
<section class="m-card mt-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold">People you invited</h2>
        <span class="text-xs m-muted">{{ $t['level1_active'] }} active · {{ $t['level1_count'] - $t['level1_active'] }} not active</span>
    </div>
    @if (count($t['level1']))
        <div class="mt-3">@include('member.partials.table', ['table' => $table])</div>
    @else
        <div class="mt-3 grid place-items-center gap-2 rounded-xl p-8 text-center text-sm m-soft">{!! I::svg('user-plus', 28) !!}<p>No one yet. Share your link from the home screen.</p><a class="m-btn mt-1" href="{{ url('/account#referral') }}">Share my link</a></div>
    @endif
    <p class="mt-4 text-xs m-muted">"Active" means their membership is paid. You earn when active people in your 3 levels pay, and only while you are active too.</p>
</section>
@endsection
