@extends('layouts.store', ['title' => 'Help and contact', 'description' => 'Answers to common questions about '.\App\Membership\Benefits::join([\App\Support\Features::enabled('shop') ? 'orders' : null, \App\Support\Features::enabled('membership') ? 'membership' : null, \App\Support\Features::enabled('referral') ? 'earnings' : null, \App\Support\Features::enabled('signals') ? 'signals' : null, 'your account']).', and how to reach us.'])
@section('content')
<div class="container-km max-w-3xl py-10">
    <h1 class="text-4xl">How can we help?</h1>
    <form method="get" action="{{ url('/help') }}" class="mt-5" role="search" data-help-search>
        <label for="help-q" class="sr-only">Search the questions</label>
        <input id="help-q" name="q" type="search" class="input" placeholder="Search, e.g. {{ implode(', ', array_slice(array_values(array_filter([\App\Support\Features::enabled('shop') ? 'delivery' : null, \App\Support\Features::enabled('wallet.cashout') ? 'cash-out' : null, \App\Support\Features::enabled('membership') ? 'renew' : null, 'password'])), 0, 3)) }}" value="{{ $q }}" autocomplete="off" maxlength="80">
    </form>

    @auth @feature('account.welcome_tour')<p class="mt-3 text-sm"><a class="underline" href="{{ url('/account?tour=1') }}" data-show-tour>Show the welcome tour</a></p>@endfeature @endauth
    <div class="mt-8 grid gap-8" data-help-list>
        @foreach ($articles as $topic => $items)
            <section data-help-group data-help-topic>
                <h2 class="text-xl">{{ \App\Help\HelpArticles::topicLabel($topic) }}</h2>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach ($items as $a)
                        <a href="{{ url('/help/'.$a['slug']) }}" class="card flex min-h-12 items-center justify-between gap-3 p-4 font-semibold hover:border-brand-500" data-help-item data-help-article="{{ $a['slug'] }}">
                            <span>{{ \App\Help\HelpTokens::apply($a['title']) }}</span><span aria-hidden="true">→</span>
                            <span hidden>{{ \App\Help\HelpArticles::keywords($a['keywords']) }} {{ \Illuminate\Support\Str::limit(\App\Help\HelpArticles::plain($a['body']), 300) }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
        @if ($faqs->isNotEmpty())<h2 class="-mb-4 text-2xl" data-help-faq-title>Questions and answers</h2>@endif
        @forelse ($faqs as $category => $items)
            <section data-help-group>
                <h2 class="text-xl">{{ \App\Support\FeatureText::mentionsOff($category) === [] ? $category : 'More questions' }}</h2>
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
            @if ($articles->isEmpty())<p class="muted">{{ $q !== '' ? 'No answer matches “'.$q.'”. Ask us below.' : 'No questions yet. Ask us below.' }}</p>@endif
        @endforelse
        <p class="muted hidden" data-help-none>No answer matches. Ask us below.</p>
    </div>

    @include('store.partials.help-contact')
</div>
@endsection
