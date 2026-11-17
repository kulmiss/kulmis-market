<section class="container-km mt-16">
    @if ($x['title'] !== '')<h2 class="text-3xl">{{ $x['title'] }}</h2>@endif
    <ol class="mt-6 grid gap-4 {{ count($x['steps']) >= 4 ? 'sm:grid-cols-2 md:grid-cols-4' : 'md:grid-cols-3' }}">
        @foreach ($x['steps'] as $i => $step)
        <li class="card">
            <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-brand-600/10 text-brand-600" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ \App\Home\SectionTypes::ICONS[$step['icon']] ?? \App\Home\SectionTypes::ICONS['check'] }}"/></svg>
            </span>
            <h3 class="mt-3 font-sans text-lg font-semibold"><span class="sr-only">Step {{ $i + 1 }}: </span>{{ $step['title'] }}</h3>
            @if ($step['text'] !== '')<p class="muted mt-1 whitespace-pre-line">{{ $step['text'] }}</p>@endif
        </li>
        @endforeach
    </ol>
</section>
