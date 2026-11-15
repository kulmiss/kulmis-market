@extends('layouts.store', ['title' => $file->title])
@section('content')
<div class="container-km max-w-xl py-14 text-center">
    <h1 class="text-3xl">{{ $file->title }}</h1>
    <p class="muted mt-3">This is your private video link. Please don't share it.</p>
    @if (str_starts_with((string) $url, 'https://'))
        <a href="{{ $url }}" class="btn-primary mt-6" rel="noopener noreferrer" target="_blank">Open the video</a>
    @endif
</div>
@endsection
