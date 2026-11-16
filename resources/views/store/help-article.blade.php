@php $title = \App\Help\HelpTokens::apply($article['title']); @endphp
@extends('layouts.store', ['title' => $title.' · Help', 'description' => \Illuminate\Support\Str::limit(\App\Help\HelpArticles::plain($article['body']), 155)])
@section('content')
<div class="container-km max-w-3xl py-8 sm:py-10">
    <nav class="muted text-sm" aria-label="Breadcrumb"><a class="underline" href="{{ url('/help') }}">Help</a> <span aria-hidden="true">›</span> {{ \App\Help\HelpArticles::topicLabel($article['topic']) }}</nav>
    <h1 class="mt-2 text-3xl sm:text-4xl">{{ $title }}</h1>
    <article class="prose-km mt-6 [&_img]:mx-auto [&_img]:max-h-[70vh] [&_img]:rounded-2xl [&_img]:border [&_img]:border-mist-200 [&_pre]:overflow-x-auto [&_pre]:rounded-xl [&_pre]:bg-mist-100 [&_pre]:p-3 [&_pre]:text-sm dark:[&_pre]:bg-ink-800 [&_table]:w-full [&_td]:border-b [&_td]:border-mist-200 [&_td]:py-2 [&_th]:py-2 [&_th]:text-start dark:[&_td]:border-ink-700" data-help-body>{!! $html !!}</article>

    @feature('site.help_feedback')
    <div class="card mt-8 flex flex-wrap items-center gap-3" data-help-feedback="{{ $article['slug'] }}">
        <span class="font-semibold">Was this helpful?</span>
        <button type="button" class="btn-ghost min-h-11 px-5" data-helpful="1">Yes</button>
        <button type="button" class="btn-ghost min-h-11 px-5" data-helpful="0">No</button>
        <span class="muted text-sm" role="status" aria-live="polite" data-helpful-msg></span>
    </div>
    @endfeature

    @if ($related)
    <section class="mt-10" aria-labelledby="related-h">
        <h2 id="related-h" class="text-xl">More about {{ mb_strtolower(\App\Help\HelpArticles::topicLabel($article['topic'])) }}</h2>
        <div class="mt-3 grid gap-2">
            @foreach ($related as $a)<a class="card flex min-h-12 items-center justify-between gap-3 p-4 font-semibold" href="{{ url('/help/'.$a['slug']) }}"><span>{{ \App\Help\HelpTokens::apply($a['title']) }}</span><span aria-hidden="true">→</span></a>@endforeach
        </div>
    </section>
    @endif

    @include('store.partials.help-contact')
</div>
@endsection
