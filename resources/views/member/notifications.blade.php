@extends('layouts.member', ['title' => 'Notifications'])
@section('content')
@php
    use App\Badges\BadgeIcons as I;
    $icons = ['earning' => 'coins', 'team' => 'users', 'badge_unlocked' => 'award', 'bonus' => 'gift', 'order' => 'shopping-bag',
        'renewal' => 'calendar-check', 'cashout' => 'wallet', 'goal' => 'target'];
    $prefNames = \App\Notify\Inbox::types(); // only kinds whose feature is on
@endphp
<h1 class="font-serif text-3xl font-semibold">Notifications</h1>
<p class="mt-1 text-sm m-muted">News about your money, your team and your orders. Newest first.</p>

@if ($items->isEmpty())
    <div class="m-card mt-5 grid place-items-center gap-2 p-8 text-center text-sm">
        {!! I::svg('bell', 28) !!}
        <p>Nothing yet. When you earn, someone joins your team or an order moves, you see it here.</p>
    </div>
@else
    <ul class="m-card mt-5 divide-y p-0" style="--tw-divide-opacity:1; border-color: var(--m-line)">
        @foreach ($items as $n)
            @php $new = in_array($n->id, $unreadIds, true); @endphp
            <li class="flex gap-3 p-4" style="border-color: var(--m-line)">
                <span class="grid size-10 shrink-0 place-items-center rounded-full m-soft m-accent-text">{!! I::svg($icons[$n->type] ?? 'bell', 19) !!}</span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">
                        @php $link = \App\Notify\Inbox::link($n->url); @endphp
                        @if ($link)<a href="{{ url($link) }}" class="hover:underline">{{ $n->title }}</a>@else{{ $n->title }}@endif
                        @if ($new)<span class="ms-1 rounded-full px-2 py-0.5 text-xs font-semibold text-white" style="background: var(--m-accent)">New</span>@endif
                    </p>
                    @if ($n->body)<p class="mt-0.5 text-sm m-muted break-words">{{ $n->body }}</p>@endif
                    <p class="mt-1 text-xs m-muted"><time datetime="{{ $n->created_at?->toIso8601String() }}">{{ $n->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i') }}</time></p>
                </div>
            </li>
        @endforeach
    </ul>
    <div class="mt-4">{{ $items->links('store.partials.pagination') }}</div>
@endif

@if (\App\Support\Features::enabled('account.push') && \App\Notify\WebPush::configured())
<section class="m-card mt-6" aria-labelledby="push-h" data-push hidden>
    <h2 id="push-h" class="font-semibold">Phone notifications</h2>
    <p class="mt-1 text-sm m-muted">Your phone shows a short note like “New team earnings”. Amounts and names never show on the lock screen.</p>
    <div class="mt-3 grid gap-1" data-push-kinds>
        @foreach (array_intersect_key(['earning' => 'Team earnings', 'order' => 'My orders', 'bonus' => 'Badge bonus', 'cashout' => 'Cash-outs', 'signal' => 'New trading signals'], $prefNames) as $k => $label)
            <label class="flex min-h-12 items-center gap-3 text-sm"><input type="checkbox" class="size-5" value="{{ $k }}" @checked(\App\Notify\NotifyPrefs::on($user, 'push.'.$k) || (! \App\Notify\NotifyPrefs::on($user, 'push.earning') && $k !== 'signal'))> {{ $label }}</label>
        @endforeach
    </div>
    <div class="mt-2 flex flex-wrap gap-2">
        <button type="button" class="m-btn" data-push-on>Turn on for this phone</button>
        <button type="button" class="m-btn-ghost" data-push-off hidden>Turn off</button>
    </div>
    <p class="mt-2 text-sm" data-push-msg role="status" aria-live="polite"></p>
</section>
@endif
@feature('account.app')
<section class="m-card mt-6" data-install hidden>
    <h2 class="font-semibold">Add to your home screen</h2>
    <p class="mt-1 text-sm m-muted">Open your account with one tap, like an app.</p>
    <button type="button" class="m-btn mt-3" data-install-btn hidden>Add to home screen</button>
    <p class="mt-2 text-sm m-muted" data-install-ios hidden>On iPhone: tap the Share button, then “Add to Home Screen”.</p>
</section>
@endfeature

<section class="m-card mt-6" aria-labelledby="prefs-h">
    <h2 id="prefs-h" class="font-semibold">What do you want to hear about?</h2>
    <form method="post" action="{{ url('/account/notifications/settings') }}" class="mt-3 grid gap-1">@csrf
        @foreach ($prefNames as $type => $label)
            <label class="flex min-h-12 items-center gap-3 text-sm">
 <input type="hidden" name="bell_{{ $type }}" value="0">
                <input type="checkbox" class="size-5" name="bell_{{ $type }}" value="1" @checked(\App\Notify\NotifyPrefs::on($user, 'bell.'.$type))> {{ $label }}
            </label>
        @endforeach
        @feature('messages.cart_reminder')
            <label class="flex min-h-12 items-center gap-3 text-sm">
                <input type="hidden" name="cart_reminder" value="0">
                <input type="checkbox" class="size-5" name="cart_reminder" value="1" @checked(\App\Notify\NotifyPrefs::on($user, 'cart_reminder'))> One reminder when I leave things in my cart
            </label>
        @endfeature
        @feature('shop.winback')
            <label class="flex min-h-12 items-center gap-3 text-sm">
                <input type="hidden" name="offers" value="0">
                <input type="checkbox" class="size-5" name="offers" value="1" @checked(\App\Notify\NotifyPrefs::on($user, 'offers'))> Now and then, a personal discount offer
            </label>
        @endfeature
        <div><button class="m-btn mt-2">Save</button></div>
    </form>
</section>
@endsection
