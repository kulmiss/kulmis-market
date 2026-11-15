@php($m = fn (int $v) => \App\Support\Money::format($v))
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Receipt {{ $payment->tx_ref }}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2b2118; }
  h1 { font-size: 18px; margin: 0 0 4px; } .muted { color: #8a7663; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  th, td { padding: 6px 4px; border-bottom: 1px solid #eadfce; text-align: left; } td.r, th.r { text-align: right; }
  .total td { font-weight: bold; border-bottom: none; font-size: 12px; }
</style></head>
<body>
  <h1><img src="data:image/png;base64,{{ base64_encode((string) file_get_contents(public_path('brand/logo-96.png'))) }}" width="28" height="28" alt="" style="vertical-align:middle;margin-right:6px">{{ \App\Support\Settings::string('site.name') }}</h1>
  <div class="muted">{{ \App\Support\Settings::string('site.tagline') }} · {{ \App\Support\Settings::string('site.support_phone') }}</div>
  <p><strong>Receipt</strong> · {{ $title }}<br>
     Paid {{ $payment->paid_at?->timezone(config('app.timezone'))->format('d M Y, H:i') }} · {{ ucfirst(str_replace('_', ' ', $payment->provider)) }}<br>
     Reference: {{ $payment->provider_reference ?? $payment->tx_ref }}</p>
  <p>Billed to: {{ trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? '')) }} {{ $customer['phone'] ?? '' }}</p>
  <table>
    <tr><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Total</th></tr>
    @if ($order)
      @foreach ($order->items as $item)
        <tr><td>{{ $item->name }}</td><td class="r">{{ $item->quantity }}</td><td class="r">{{ $m($item->unit_price) }}</td><td class="r">{{ $m($item->line_total) }}</td></tr>
      @endforeach
      @if ($order->coupon_discount)<tr><td colspan="3">Coupon {{ $order->coupon_code }}</td><td class="r">-{{ $m($order->coupon_discount) }}</td></tr>@endif
      <tr><td colspan="3">Shipping</td><td class="r">{{ $m($order->shippingPayable()) }}</td></tr>
      @if ($order->wallet_used)<tr><td colspan="3">Paid from wallet</td><td class="r">-{{ $m($order->wallet_used) }}</td></tr>@endif
    @else
      <tr><td>{{ $title }}</td><td class="r">1</td><td class="r">{{ $m($payment->amount) }}</td><td class="r">{{ $m($payment->amount) }}</td></tr>
    @endif
    <tr class="total"><td colspan="3">Paid</td><td class="r">{{ $m($payment->amount) }}</td></tr>
    @if ($payment->refunded_amount)<tr><td colspan="3">Refunded</td><td class="r">-{{ $m($payment->refunded_amount) }}</td></tr>@endif
  </table>
  <p class="muted">Thank you for supporting Ethiopian handcraft.</p>
</body></html>
