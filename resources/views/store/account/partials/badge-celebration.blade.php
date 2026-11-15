{{-- In-app celebration for newly unlocked badges ($unseen from BadgeEngine::summary). Works without JS (the form marks them seen). --}}
@if (!empty($unseen))
<div class="km-celebrate fixed inset-0 z-50 grid place-items-center bg-coffee-900/60 p-4" role="dialog" aria-modal="true" aria-labelledby="celebrate-title" data-celebrate>
    <div class="km-confetti" aria-hidden="true">@for ($i = 0; $i < 24; $i++)<i></i>@endfor</div>
    <div class="card relative w-full max-w-sm text-center">
        <div class="mx-auto flex w-fit -space-x-3">
            @foreach (array_slice($unseen, 0, 3) as $b)
                <span class="km-pop grid size-20 place-items-center rounded-full text-white ring-4 ring-white dark:ring-coffee-900" style="background: {{ $b['color'] }}">{!! \App\Badges\BadgeIcons::svg($b['icon'], 38) !!}</span>
            @endforeach
        </div>
        <h2 id="celebrate-title" class="mt-4 text-2xl">{{ count($unseen) === 1 ? 'New badge unlocked!' : count($unseen).' new badges unlocked!' }}</h2>
        <p class="muted mt-1">{{ collect($unseen)->pluck('name')->join(', ', ' and ') }}</p>
        <form method="post" action="{{ url('/account/badges/seen') }}" class="mt-5 grid gap-2" data-celebrate-form>
            @csrf
            <button class="btn-primary">Yay! 🎉</button>
            <a class="text-sm underline" href="{{ url('/account/badges') }}">See all my badges</a>
        </form>
    </div>
</div>
@endif
