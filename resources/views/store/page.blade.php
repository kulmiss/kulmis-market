@extends('layouts.store', ['title' => $page->title, 'description' => $page->seo_description])
@section('content')
<article class="container-km max-w-3xl py-12">
    <h1 class="text-4xl">{{ $page->title }}</h1>
    <p class="muted mt-2 text-sm">Last updated {{ $page->updated_at?->timezone(config('app.timezone'))->format('d M Y') }}</p>
    <div class="prose-km mt-8">{!! $page->html() !!}</div>
</article>
@endsection
