{{-- Numbers from Settings right now. Honest: an example, never a promise. --}}
<section class="container-km mt-16">
    <div class="card md:p-8">
        @if ($x['title'] !== '')<h2 class="text-3xl">{{ $x['title'] }}</h2>@endif
        @if ($x['text'] !== '')<p class="muted mt-2 max-w-2xl whitespace-pre-line">{{ $x['text'] }}</p>@endif
        <p class="mt-4 text-lg">Example: 3 people join with your link and stay. You earn <b>{{ $x['three'] }} each month they stay</b> ({{ $x['one'] }} from each {{ $x['price'] }} membership).</p>
        <ul class="muted mt-3 grid gap-1">
            @if ($x['l2'])<li>• When they bring people too: {{ $x['l2'] }} a month for each of those (level 2){{ $x['l3'] ? ', and '.$x['l3'].' for each person after that (level 3)' : '' }}.</li>@endif
            <li>• Earnings are ready to use {{ $x['pending_days'] }} days after each payment, while your own membership is paid.</li>
            <li>• This example uses today's prices. What you earn depends on how many people join and how long they stay. It is an example, not a promise.</li>
        </ul>
        @if ($x['button'])<div class="mt-6">@include('store.home.button', ['btn' => $x['button'], 'onDark' => false, 'dark' => true])</div>@endif
    </div>
</section>
