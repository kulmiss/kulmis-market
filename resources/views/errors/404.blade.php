@extends('errors.layout')
@section('code', '404')
@section('title')
We can't find that page
@endsection
@section('message')
It may have moved, or the link has a typo.@if (rescue(fn () => \App\Support\Features::enabled('shop'), true, false)) Our handmade pieces are still all here.@endif
@endsection

