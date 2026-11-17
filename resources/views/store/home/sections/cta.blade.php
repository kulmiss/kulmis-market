<section class="container-km mt-16">
    <div class="{{ trim('rounded-[2rem] px-6 py-10 text-center md:px-12 md:py-14 '.$x['accent']['class']) }}" @if ($x['accent']['style']) style="{{ $x['accent']['style'] }}" @endif>
        <h2 class="text-3xl md:text-4xl">{{ $x['title'] }}</h2>
        @if ($x['text'] !== '')<p class="mx-auto mt-3 max-w-xl whitespace-pre-line {{ $x['accent']['onDark'] ? 'opacity-90' : 'muted' }}">{{ $x['text'] }}</p>@endif
        @if ($x['button'])<div class="mt-7">@include('store.home.button', ['btn' => $x['button'], 'onDark' => $x['accent']['onDark']])</div>@endif
    </div>
</section>
