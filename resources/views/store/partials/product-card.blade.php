<a href="{{ url('/shop/'.$p->slug) }}" class="group block">
    <div class="relative aspect-square overflow-hidden rounded-2xl bg-mist-100 dark:bg-ink-800">
        @include('store.partials.picture', ['image' => $p->images->first(), 'class' => 'h-full w-full object-cover transition duration-500 group-hover:scale-105', 'eager' => $eager ?? false])
        <div class="absolute start-2 top-2 flex flex-wrap gap-1">
            @if ($p->is_members_only)<span class="badge-gold bg-white/90">Members only</span>@endif
            @if ($p->inEarlyAccess())<span class="badge bg-white/90 text-ink-800 dark:bg-ink-900/90 dark:text-mist-100">Early access</span>@endif
            @if ($p->isMadeToOrder())<span class="badge bg-white/90 text-ink-800 dark:bg-ink-900/90 dark:text-mist-100">Made to order</span>@endif
        </div>
    </div>
    <h3 class="mt-3 font-sans text-base font-medium group-hover:text-brand-600">{{ $p->name }}</h3>
    <div class="mt-1 text-sm">@include('store.partials.price', ['price' => $p->price])</div>
</a>
