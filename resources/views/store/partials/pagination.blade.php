{{-- Simple, accessible pagination for shop lists (Previous · 1 2 3 · Next). --}}
@if ($paginator->hasPages())
<nav class="mt-10 flex flex-wrap items-center justify-center gap-2 text-sm" aria-label="Pages">
    @if ($paginator->onFirstPage())
        <span class="btn-ghost opacity-50">← Previous</span>
    @else
        <a class="btn-ghost" href="{{ $paginator->previousPageUrl() }}" rel="prev">← Previous</a>
    @endif
    @foreach ($elements as $element)
        @if (is_string($element))
            <span class="px-2 muted">…</span>
        @else
            @foreach ($element as $page => $url)
                @if ($page == $paginator->currentPage())
                    <span class="btn-primary" aria-current="page">{{ $page }}</span>
                @else
                    <a class="btn-ghost" href="{{ $url }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach
    @if ($paginator->hasMorePages())
        <a class="btn-ghost" href="{{ $paginator->nextPageUrl() }}" rel="next">Next →</a>
    @else
        <span class="btn-ghost opacity-50">Next →</span>
    @endif
</nav>
@endif
