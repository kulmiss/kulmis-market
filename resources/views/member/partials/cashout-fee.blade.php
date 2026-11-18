{{-- The cash-out fee (if any), under the cash-out form or the "not enough yet" line. --}}
@feature('wallet.cashout_fee')
    @php $fx = \App\Support\Settings::int('wallet.cashout_fee_fixed'); $fb = \App\Support\Settings::int('wallet.cashout_fee_bps'); $fmt = fn ($v) => \App\Support\Money::format((int) $v); @endphp
    @if ($fx > 0 || $fb > 0)
        <p class="basis-full text-xs m-muted">Cash-out fee: {{ trim(($fx > 0 ? $fmt($fx) : '').($fx > 0 && $fb > 0 ? ' + ' : '').($fb > 0 ? rtrim(rtrim(number_format($fb / 100, 2), '0'), '.').'%' : '')) }}. You get the amount minus the fee.</p>
    @endif
@endfeature
