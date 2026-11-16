{{-- "How you earn": a worked example in ETB from the real settings, and a small calculator (resources/js/app.js: data-earn-calc). --}}
@php
    use App\Support\Features as F;
    use App\Support\Money;
    use App\Support\Settings;
    $price = Settings::int('membership.price');
    $on = fn (int $l) => F::enabled('membership') && F::enabled("referral.level$l") && F::enabled('referral.commission.membership');
    $rate = fn (int $l) => $on($l) ? intdiv($price * Settings::int("referral.rate.membership.l$l"), 10_000) : 0;
    $three = app(\App\Reports\WhatIf::class)->forMember(3);
    $calc = [
        'price' => $price, 'rates' => [$rate(1), $rate(2), $rate(3)],
        'first' => F::enabled('referral.first_payment_bonus') ? Settings::int('bonus.first_payment') : 0,
        'teamLimit' => Settings::int('bonus.team_limit'),
        'ladder' => F::enabled('badges.bonus') ? \App\Models\Badge::query()->where('is_active', true)->where('rule', 'active_level1')->where('monthly_bonus', '>', 0)
            ->orderByDesc('threshold')->get(['threshold', 'monthly_bonus', 'name'])->map(fn ($b) => [(int) $b->threshold, (int) $b->monthly_bonus, $b->name])->all() : [],
    ];
@endphp
<section class="m-card mt-3" aria-labelledby="earn-h" data-how-you-earn>
    <h2 id="earn-h" class="font-semibold">How you earn</h2>
    @if ($rate(1) > 0)
    <p class="mt-2 text-sm">Invite 3 people who join: <b>you earn {{ Money::format(3 * $rate(1)) }} every month they stay</b> ({{ Money::format($rate(1)) }} from each {{ Money::format($price) }} membership).</p>
    <ul class="mt-2 grid gap-1 text-sm m-muted">
        @if ($rate(2) > 0)<li>• When they invite people too: {{ Money::format($rate(2)) }} a month for each of those (level 2){{ $rate(3) > 0 ? ', and '.Money::format($rate(3)).' for each person they bring (level 3)' : '' }}.</li>@endif
        @if ($calc['first'] > 0)<li>• {{ Money::format($calc['first']) }} once, when someone you invited pays for the first time.</li>@endif
        @if ($three['badge_bonus'] > 0)<li>• With 3 active members you invited: the {{ $calc['ladder'][count($calc['ladder']) - 1][2] ?? 'Bronze' }} badge, {{ Money::format($three['badge_bonus']) }} extra every month.</li>@endif
        <li>• Earnings are ready to use {{ Settings::int('referral.pending_days') }} days after each payment@if (F::enabled('membership')), and only while your own membership is paid@endif.</li>
    </ul>
    @endif
    <div class="mt-4 rounded-xl p-3 m-soft" data-earn-calc="{{ json_encode($calc) }}">
        <p class="text-sm font-semibold">What you could earn</p>
        <div class="mt-2 grid grid-cols-2 gap-3">
            <label class="grid gap-1 text-xs">People you invite who pay<input type="number" min="0" max="500" value="3" class="m-input" data-earn-direct inputmode="numeric"></label>
            <label class="grid gap-1 text-xs">People each of them invites<input type="number" min="0" max="50" value="0" class="m-input" data-earn-theirs inputmode="numeric"></label>
        </div>
        <dl class="mt-3 grid gap-1 text-sm" data-earn-result aria-live="polite"></dl>
    </div>
</section>
