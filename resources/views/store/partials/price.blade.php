{{-- Guest price + "Member price" next to it, everywhere. --}}
@php
    $calc = app(\App\Pricing\PriceCalculator::class);
    $memberPrice = \App\Support\Features::enabled('membership') ? $calc->memberPrice($price) : $price;
@endphp
<span class="flex flex-wrap items-baseline gap-x-2">
    <span class="{{ ($isMember ?? false) && $memberPrice < $price ? 'muted line-through text-sm' : 'font-semibold' }}">{{ \App\Support\Money::format($price) }}</span>
    @if ($memberPrice < $price)
        <span class="font-semibold text-clay-600 dark:text-clay-500">{{ \App\Support\Money::format($memberPrice) }} <span class="text-xs font-normal">member price</span></span>
    @endif
</span>
