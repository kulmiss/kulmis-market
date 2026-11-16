{{-- A small "?" to the help article about this screen ($slug). Hidden when the help centre or the article is off. --}}
@if (\App\Support\Features::enabled('site.help_centre') && \App\Help\HelpArticles::exists($slug))
<a href="{{ url('/help/'.$slug) }}" class="inline-grid size-8 shrink-0 place-items-center rounded-full align-middle text-sm font-bold m-soft m-accent-text" aria-label="Help: {{ $label ?? 'how this works' }}" title="Help" data-help-q="{{ $slug }}">?</a>
@endif
