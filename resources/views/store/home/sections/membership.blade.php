@php($head = $x['title'] !== '' || $x['text'] !== '')
@if ($head)
<div class="container-km mt-16">
    @if ($x['title'] !== '')<h2 class="text-3xl">{{ $x['title'] }}</h2>@endif
    @if ($x['text'] !== '')<p class="muted mt-2 max-w-2xl whitespace-pre-line">{{ $x['text'] }}</p>@endif
</div>
@endif
@if ($x['benefits'])
<section class="container-km {{ $head ? 'mt-6' : 'mt-16' }} grid gap-4 md:grid-cols-4">
    @feature('membership.member_discount')<div class="card"><p class="text-3xl font-semibold text-brand-600">{{ $x['discount'] }}%</p><p class="muted">off every product for members</p></div>@endfeature
    @feature('shop.free_shipping')<div class="card"><p class="text-3xl font-semibold text-brand-600">Free</p><p class="muted">shipping over {{ $x['free_over'] }}</p></div>@endfeature
    @feature('shop.early_access')<div class="card"><p class="text-3xl font-semibold text-brand-600">{{ $x['early_hours'] }} h</p><p class="muted">early access to new pieces</p></div>@endfeature
    @feature('signals')<div class="card"><p class="text-3xl font-semibold text-brand-600">Included</p><p class="muted">trading signals for members</p></div>@endfeature
</section>
@endif
@if ($x['button'])<div class="container-km mt-6 text-center"><a href="{{ $x['button']['href'] }}" class="btn-dark">{{ $x['button']['text'] }}</a></div>@endif
