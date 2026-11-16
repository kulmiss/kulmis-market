@extends('layouts.member', ['title' => 'Addresses'])
@section('content')
@php use App\Badges\BadgeIcons as I; @endphp
<h1 class="font-serif text-3xl font-semibold">Delivery addresses</h1>
<p class="mt-1 text-sm m-muted">Save the places we deliver to. At checkout you pick one with a tap.</p>
@if ($errors->any())<p class="mt-3 rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-300" role="alert">{{ $errors->first() }}</p>@endif

@php
    $fields = function ($a = null) {
        return [
            ['label', 'Name for this address (e.g. Home, Work)', $a?->label ?? 'Home', 'text', false],
            ['first_name', 'First name', $a?->first_name ?? auth()->user()->first_name, 'text', true],
            ['last_name', 'Last name', $a?->last_name ?? auth()->user()->last_name, 'text', false],
            ['phone', 'Phone', $a?->phone ?? auth()->user()->phone, 'tel', true],
            ['city', 'City', $a?->city ?? '', 'text', true],
        ];
    };
@endphp

<ul class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-2">
    @foreach ($addresses as $a)
        <li class="m-card">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold">{{ $a->label }} @if ($a->is_default)<span class="ms-1 rounded-full px-2 py-0.5 text-xs m-soft m-accent-text">Default</span>@endif</p>
                    <p class="mt-1 text-sm break-words">{{ trim($a->first_name.' '.$a->last_name) }} · {{ $a->phone }}<br>{{ $a->city }}, {{ $a->address }}</p>
                </div>
                <form method="post" action="{{ url('/account/addresses/'.$a->id) }}" data-confirm="Delete this address?">@csrf @method('delete')
                    <button class="m-btn-ghost min-h-10" aria-label="Delete {{ $a->label }}">Delete</button>
                </form>
            </div>
            <details class="mt-2">
                <summary class="min-h-12 cursor-pointer text-sm m-accent-text">Change</summary>
                <form method="post" action="{{ url('/account/addresses/'.$a->id) }}" class="grid gap-3 pt-2">@csrf @method('put')
                    @foreach ($fields($a) as [$name, $label, $value, $type, $req])
                        <label class="grid gap-1 text-sm"><span>{{ $label }}</span><input class="m-input min-h-12" name="{{ $name }}" type="{{ $type }}" value="{{ $value }}" @required($req) maxlength="80"></label>
                    @endforeach
                    <label class="grid gap-1 text-sm"><span>Address (area, street, landmark)</span><textarea class="m-input" name="address" rows="2" required maxlength="500">{{ $a->address }}</textarea></label>
                    <label class="flex min-h-12 items-center gap-2 text-sm"><input type="checkbox" name="is_default" value="1" class="size-5" @checked($a->is_default)> Use this one first</label>
                    <button class="m-btn min-h-12">Save this address</button>
                </form>
            </details>
        </li>
    @endforeach
</ul>

@if ($addresses->count() < \App\Models\Address::MAX_PER_USER)
<section class="m-card mt-3">
    <h2 class="font-semibold">Add an address</h2>
    <form method="post" action="{{ url('/account/addresses') }}" class="mt-3 grid gap-3 sm:grid-cols-2">@csrf
        @foreach ($fields() as [$name, $label, $value, $type, $req])
            <label class="grid gap-1 text-sm"><span>{{ $label }}</span><input class="m-input min-h-12" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}" @required($req) maxlength="80"></label>
        @endforeach
        <label class="grid gap-1 text-sm sm:col-span-2"><span>Address (area, street, landmark)</span><textarea class="m-input" name="address" rows="2" required maxlength="500">{{ old('address') }}</textarea></label>
        <label class="flex min-h-12 items-center gap-2 text-sm"><input type="checkbox" name="is_default" value="1" class="size-5"> Use this one first</label>
        <button class="m-btn min-h-12 sm:col-span-2">{!! I::svg('plus', 16) !!}Save address</button>
    </form>
</section>
@endif
@endsection
