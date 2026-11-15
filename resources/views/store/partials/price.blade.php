{{-- Guest price + "Member price" next to it, everywhere. A live flash sale ($sale) shows the old price crossed out. --}}
@php
    $calc = app(\App\Pricing\PriceCalculator::class);
    $regularPrice = $price;
    if (! empty($sale) && $sale->price < $price) { $price = $sale->price; }
    $memberPrice = \App\Support\Features::enabled('membership') ? $calc->memberPrice($price) : $price;
@endphp
<span class="flex flex-wrap items-baseline gap-x-2">
    @if ($price < $regularPrice)<span class="muted text-sm line-through"><span class="sr-only">Was </span>{{ \App\Support\Money::format($regularPrice) }}</span>@endif
    <span class="{{ ($isMember ?? false) && $memberPrice < $price ? 'muted line-through text-sm' : 'font-semibold' }}">{{ \App\Support\Money::format($price) }}</span>
    @if ($memberPrice < $price)
        <span class="font-semibold text-brand-600 dark:text-brand-500">{{ \App\Support\Money::format($memberPrice) }} <span class="text-xs font-normal">member price</span></span>
    @endif
</span>
