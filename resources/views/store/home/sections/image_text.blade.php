@php($a = $x['accent'])
<section class="container-km mt-16">
    <div class="{{ trim('grid items-center gap-8 '.($x['image'] ? 'md:grid-cols-2 ' : '').($a['filled'] ? 'rounded-[2rem] p-6 md:p-10 ' : '').$a['class']) }}" @if ($a['style']) style="{{ $a['style'] }}" @endif>
        @if ($x['image'])<div class="aspect-[4/3] overflow-hidden rounded-2xl{{ $d['image_side'] === 'right' ? ' md:order-last' : '' }}">@include('store.partials.picture', ['image' => $x['image'], 'sizes' => '(min-width: 768px) 50vw, 100vw'])</div>@endif
        <div>
            @if ($x['title'] !== '')<h2 class="text-3xl">{{ $x['title'] }}</h2>@endif
            @if ($x['text'] !== '')<p class="mt-3 whitespace-pre-line {{ $a['onDark'] ? 'opacity-90' : 'muted' }}">{{ $x['text'] }}</p>@endif
            @if ($x['button'])<div class="mt-6">@include('store.home.button', ['btn' => $x['button'], 'onDark' => $a['onDark']])</div>@endif
        </div>
    </div>
</section>
