{{-- data-flash-sale: the countdown (app.js) removes this part when the sale ends, even on a saved copy of the page. --}}
<section class="container-km mt-12" data-flash-sale>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-2">
        <div>
            @if ($x['title'] !== '')<h2 class="text-3xl">{{ $x['title'] }}</h2>@endif
            @if ($x['text'] !== '')<p class="muted mt-1">{{ $x['text'] }}</p>@endif
        </div>
        <p class="badge bg-ink-900 text-white dark:bg-mist-100 dark:text-ink-900">Ends in&nbsp;<span class="tabular-nums" data-countdown="{{ $x['ends']->toIso8601String() }}">{{ $x['ends']->diffForHumans(null, \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span></p>
    </div>
    <div class="grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-4">
        @foreach ($x['products'] as $p)@include('store.partials.product-card', ['p' => $p])@endforeach
    </div>
</section>
