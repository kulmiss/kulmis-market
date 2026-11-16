@extends('layouts.store', ['title' => 'Help and contact', 'description' => 'Answers to common questions about '.\App\Membership\Benefits::join([\App\Support\Features::enabled('shop') ? 'orders' : null, \App\Support\Features::enabled('membership') ? 'membership' : null, \App\Support\Features::enabled('referral') ? 'earnings' : null, \App\Support\Features::enabled('signals') ? 'signals' : null, 'your account']).', and how to reach us.'])
@section('content')
<div class="container-km max-w-3xl py-10">
    <h1 class="text-4xl">How can we help?</h1>
    <form method="get" action="{{ url('/help') }}" class="mt-5" role="search" data-help-search>
        <label for="help-q" class="sr-only">Search the questions</label>
        <input id="help-q" name="q" type="search" class="input" placeholder="Search, e.g. {{ implode(', ', array_slice(array_values(array_filter([\App\Support\Features::enabled('shop') ? 'delivery' : null, \App\Support\Features::enabled('wallet.cashout') ? 'cash-out' : null, \App\Support\Features::enabled('membership') ? 'renew' : null, 'password'])), 0, 3)) }}" value="{{ $q }}" autocomplete="off" maxlength="80">
    </form>

    <div class="mt-8 grid gap-8" data-help-list>
        @forelse ($faqs as $category => $items)
            <section data-help-group>
                <h2 class="text-xl">{{ $category }}</h2>
                <div class="mt-3 grid gap-2">
                    @foreach ($items as $f)
                        <details class="card group p-0" data-help-item>
                            <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 p-4 font-semibold">
                                <span>{{ $f->question }}</span><span aria-hidden="true" class="transition group-open:rotate-45">+</span>
                            </summary>
                            <div class="muted px-4 pb-4 leading-relaxed">{!! nl2br(e($f->answerNow())) !!}</div>
                        </details>
                    @endforeach
                </div>
            </section>
        @empty
            <p class="muted">{{ $q !== '' ? 'No answer matches “'.$q.'”. Ask us below.' : 'No questions yet. Ask us below.' }}</p>
        @endforelse
        <p class="muted hidden" data-help-none>No answer matches. Ask us below.</p>
    </div>

    <section class="mt-12" aria-labelledby="contact-h">
        <h2 id="contact-h" class="text-2xl">Still need help?</h2>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($whatsapp)<a class="btn-ghost" href="{{ $whatsapp }}" rel="noopener" target="_blank">WhatsApp</a>@endif
            @if ($telegram)<a class="btn-ghost" href="{{ $telegram }}" rel="noopener" target="_blank">Telegram</a>@endif
            @if ($email)<a class="btn-ghost" href="mailto:{{ $email }}">Email {{ $email }}</a>@endif
        </div>
        <form class="card mt-5 grid gap-4" data-contact-form novalidate>
            <p class="muted -mb-1 text-sm">Or write to us here. We answer within one working day.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label><span class="label">Your name</span><input name="name" class="input" required maxlength="80" autocomplete="given-name"></label>
                <label><span class="label">Phone or email</span><input name="contact" class="input" required maxlength="160" autocomplete="email" placeholder="09… or you@example.com"></label>
            </div>
            <label><span class="label">About</span>
                <select name="topic" class="input">@foreach ($topics as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach</select>
            </label>
            <label><span class="label">Your message</span><textarea name="message" class="input min-h-32" required minlength="10" maxlength="2000"></textarea></label>
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true" aria-label="Leave this empty">
            <button class="btn-primary">Send</button>
            <p class="text-sm" data-form-message role="status" aria-live="polite"></p>
        </form>
    </section>
</div>
@endsection
