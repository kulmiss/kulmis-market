{{-- Product reviews: verified buyers only, approved by the Super Admin. --}}
@php
    $r = app(\App\Shop\Reviews::class)->forProduct($product);
    $me = auth()->user();
    $canReview = $me && app(\App\Shop\Reviews::class)->canReview($me, $product);
    $starRow = fn (int $n, string $size = 'size-4') => collect(range(1, 5))->map(fn ($i) => '<svg class="'.$size.' '.($i <= $n ? 'text-amber-500' : 'text-mist-300 dark:text-ink-600').'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4 6.1 20.5l1.2-6.5L2.5 9.4l6.6-.9z"/></svg>')->implode('');
@endphp
<section class="container-km mt-10" id="reviews" aria-labelledby="reviews-title">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <h2 id="reviews-title" class="text-2xl">Reviews</h2>
        @if ($r['count'])
            <p class="flex items-center gap-2 text-sm"><span class="flex" role="img" aria-label="{{ $r['avg'] }} out of 5 stars">{!! $starRow((int) round($r['avg'])) !!}</span><b>{{ $r['avg'] }}</b> · {{ $r['count'] }} {{ \Illuminate\Support\Str::plural('review', $r['count']) }} from buyers</p>
        @endif
    </div>
    @if ($r['count'])
        <div class="mt-4 grid gap-6 md:grid-cols-3">
            <div class="grid content-start gap-1 text-sm">
                @foreach ([5, 4, 3, 2, 1] as $s)
                    @php $c = $r['stars'][$s] ?? 0; @endphp
                    <div class="flex items-center gap-2"><span class="w-10 tabular-nums">{{ $s }} ★</span><span class="h-2 flex-1 overflow-hidden rounded-full bg-mist-100 dark:bg-ink-800"><span class="block h-full rounded-full bg-amber-500" style="width: {{ $r['count'] ? round($c * 100 / $r['count']) : 0 }}%"></span></span><span class="w-8 text-end tabular-nums muted">{{ $c }}</span></div>
                @endforeach
            </div>
            <ul class="grid gap-4 md:col-span-2">
                @foreach ($r['items'] as $rev)
                    <li class="card">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="flex" role="img" aria-label="{{ $rev->rating }} out of 5 stars">{!! $starRow($rev->rating) !!}</span>
                            <span class="text-xs muted">{{ $rev->authorName() }} · Verified buyer · {{ $rev->created_at?->timezone(config('app.timezone'))->format('j M Y') }}</span>
                        </div>
                        @if ($rev->title)<p class="mt-2 font-semibold">{{ $rev->title }}</p>@endif
                        <p class="mt-1 whitespace-pre-line break-words text-sm">{{ $rev->body }}</p>
                        @if ($rev->photos)
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($rev->photos as $photo)
                                    <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($photo) }}" target="_blank" rel="noopener" class="block size-20 overflow-hidden rounded-lg"><img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($photo) }}" alt="Photo from {{ $rev->authorName() }}" class="h-full w-full object-cover" loading="lazy" width="80" height="80"></a>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <p class="muted mt-2 text-sm">No reviews yet. People who bought it can write one after it is delivered.</p>
    @endif

    @if ($canReview)
        <form method="post" action="{{ url('/account/reviews/'.$product->id) }}" enctype="multipart/form-data" class="card mt-6 grid gap-4">@csrf
            <h3 class="text-lg font-semibold">Write your review</h3>
            @if ($errors->any())<p class="text-sm text-danger-600" role="alert">{{ $errors->first() }}</p>@endif
            <fieldset class="grid gap-2"><legend class="label">Your stars</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach ([5, 4, 3, 2, 1] as $s)
                        <label class="flex min-h-12 cursor-pointer items-center gap-1 rounded-xl border px-3 has-[:checked]:border-amber-500 has-[:checked]:bg-amber-500/10"><input type="radio" name="rating" value="{{ $s }}" class="size-5" required @checked(old('rating') == $s)> {{ $s }} ★</label>
                    @endforeach
                </div>
            </fieldset>
            <label><span class="label">Title (optional)</span><input name="title" class="input" maxlength="80" value="{{ old('title') }}"></label>
            <label><span class="label">What did you like? How is the quality?</span><textarea name="body" class="input" rows="4" required minlength="10" maxlength="2000">{{ old('body') }}</textarea></label>
            <label><span class="label">Photos (optional, up to 3)</span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple class="input"></label>
            <button class="btn-primary">Send my review</button>
        </form>
    @endif
</section>
