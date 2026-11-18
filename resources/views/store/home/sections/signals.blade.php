<section class="container-km mt-16">
    <div class="{{ trim('rounded-[2rem] p-6 md:p-10 '.$x['accent']['class']) }}" @if ($x['accent']['style']) style="{{ $x['accent']['style'] }}" @endif>
        @if ($x['title'] !== '')<h2 class="text-3xl">{{ $x['title'] }}</h2>@endif
        @if ($x['text'] !== '')<p class="mt-3 max-w-2xl whitespace-pre-line {{ $x['accent']['onDark'] ? 'opacity-90' : 'muted' }}">{{ $x['text'] }}</p>@endif
        @if ($x['button'])<div class="mt-6">@include('store.home.button', ['btn' => $x['button'], 'onDark' => $x['accent']['onDark']])</div>@endif
    </div>
</section>
