@extends('layouts.member', ['title' => 'Share kit'])
@section('content')
<div class="flex items-center gap-2"><h1 class="font-serif text-3xl font-semibold">Share kit</h1>@include('member.partials.help-link', ['slug' => 'share-your-link', 'label' => 'sharing'])</div>
<p class="mt-1 text-sm m-muted">Ready-made posts with your code <b class="font-mono">{{ $user->referral_code }}</b>. Copy{{ \App\Support\Features::enabled('referral.qr_codes') ? ', send, or make a picture card with your QR code' : ' and send them' }}.</p>
<div class="mt-5 grid gap-4 lg:grid-cols-2">
    @foreach ($items as $row)
        @php $i = $row['item']; @endphp
        <section class="m-card grid gap-3" aria-label="{{ $i->title }}">
            <div class="flex items-center gap-3">
                <img src="{{ $i->thumbUrl() }}" alt="" width="64" height="64" class="size-16 rounded-xl object-cover" loading="lazy">
                <h2 class="font-semibold">{{ $i->title }}</h2>
            </div>
            @include('member.partials.share-card', ['text' => $row['text'], 'buttons' => $row['buttons'], 'image' => $i->imageUrl(), 'title' => $i->title,
                'line' => \App\Support\Settings::string('site.name'), 'code' => $user->referral_code, 'link' => $row['link'],
                'qr' => \App\Support\Features::enabled('referral.qr_codes') ? url('/account/referral-qr.svg').($i->path() !== '' ? '?to='.$i->path() : '') : ''])
        </section>
    @endforeach
</div>
@endsection
