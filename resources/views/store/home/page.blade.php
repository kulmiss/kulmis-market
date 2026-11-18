{{-- Home page from the builder (Admin → Home page). Each section is one partial in sections/. --}}
@extends('layouts.store')
@php
    $lead = collect($blocks)->first(fn ($b) => $b['type'] === 'hero' && $b['x']['eager'] && $b['x']['image']);
@endphp
@push('head')@if ($lead)@include('store.partials.preload-image', ['image' => $lead['x']['image'], 'sizes' => $lead['d']['layout'] === 'behind' ? '100vw' : '(min-width: 768px) 50vw, 100vw'])@endif @endpush
@section('content')
@foreach ($blocks as $b)
@if ($preview)<div data-home-section="{{ $b['id'] }}">@endif
@include('store.home.sections.'.$b['type'], ['d' => $b['d'], 'x' => $b['x'], 'sid' => $b['id']])
@if ($preview)</div>@endif
@endforeach
@endsection
