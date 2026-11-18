<div class="container-km mt-8"><div class="{{ trim('rounded-2xl px-4 py-3 text-center text-sm '.$x['accent']['class']) }}" @if ($x['accent']['style']) style="{{ $x['accent']['style'] }}" @endif>
    {{ $x['text'] }}@if ($x['button'])<a class="ms-2 font-semibold underline" href="{{ $x['button']['href'] }}">{{ $x['button']['text'] }}</a>@endif
</div></div>
