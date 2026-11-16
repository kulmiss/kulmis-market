@extends('layouts.store', ['title' => $title, 'noindex' => true])
@section('content')
<div class="container-km max-w-xl py-16 text-center">
    <h1 class="text-3xl">{{ $title }}</h1>
    <p class="muted mt-4 text-lg">{{ $text }}</p>
    <a href="{{ url('/') }}" class="btn-primary mt-8">Back to the shop</a>
</div>
@endsection
