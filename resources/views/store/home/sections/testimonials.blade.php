{{-- Only quotes the Super Admin marked as said by a real customer (demo ones only while demo data is shown, marked "Demo"). --}}
<section class="container-km mt-16">
    @if ($x['title'] !== '')<h2 class="text-3xl">{{ $x['title'] }}</h2>@endif
    <div class="mt-6 grid gap-4 {{ count($x['items']) === 1 ? 'max-w-2xl' : (count($x['items']) === 2 ? 'md:grid-cols-2' : 'md:grid-cols-3') }}">
        @foreach ($x['items'] as $t)
        <figure class="card flex flex-col">
            <blockquote class="flex-1 text-lg leading-relaxed whitespace-pre-line">“{{ $t['text'] }}”</blockquote>
            <figcaption class="mt-4 flex items-center gap-3">
                @if ($t['photo'])<img src="{{ $t['photo'] }}" alt="" width="48" height="48" loading="lazy" decoding="async" class="h-12 w-12 rounded-full object-cover">@endif
                <span><span class="block font-semibold">{{ $t['name'] }}</span>@if ($t['city'] !== '')<span class="muted block text-sm">{{ $t['city'] }}</span>@endif</span>
                @if ($t['demo'])<span class="badge ms-auto">Demo</span>@endif
            </figcaption>
        </figure>
        @endforeach
    </div>
</section>
