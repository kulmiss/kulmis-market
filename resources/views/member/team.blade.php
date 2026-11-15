@php use App\Badges\BadgeIcons as I; $t = $ref['team']; @endphp
@extends('layouts.member', ['title' => 'Team'])
@section('content')
<h1 class="font-serif text-3xl font-semibold">Your team</h1>
<p class="mt-1 text-sm m-muted">People you invited (level 1) and the people they invited (levels 2 and 3). For privacy you see names only for level 1.</p>
<section class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
    @include('member.partials.stat', ['label' => 'Level 1', 'value' => $t['level1_count'], 'icon' => 'users', 'tone' => 'accent', 'sub' => $t['level1_active'].' active'])
    @include('member.partials.stat', ['label' => 'Level 2', 'value' => $t['level2_count'], 'icon' => 'network'])
    @include('member.partials.stat', ['label' => 'Level 3', 'value' => $t['level3_count'], 'icon' => 'network'])
</section>
<section class="m-card mt-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-semibold">Level 1: people you invited</h2>
        <span class="text-xs m-muted">{{ $t['level1_active'] }} active · {{ $t['level1_count'] - $t['level1_active'] }} not active</span>
    </div>
    @if (count($t['level1']))
        <div class="mt-3">@include('member.partials.table', ['table' => $table])</div>
    @else
        <div class="mt-3 grid place-items-center gap-2 rounded-xl p-8 text-center text-sm m-soft">{!! I::svg('user-plus', 28) !!}<p>No one yet. Share your link from the home screen.</p><a class="m-btn mt-1" href="{{ url('/account#referral') }}">Share my link</a></div>
    @endif
    <p class="mt-4 text-xs m-muted">"Active" means a paying member. Only active members earn, and you earn from active members' payments in your 3 levels.</p>
</section>
@endsection
