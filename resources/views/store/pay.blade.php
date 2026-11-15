@extends('layouts.store', ['title' => 'Pay for order '.$order->number])
@section('content')
<div class="container-km max-w-3xl py-10" data-pay-page data-number="{{ $order->number }}" data-phone="{{ $phone }}">
    <h1 class="text-4xl">Pay for order {{ $order->number }}</h1>
    @if ($order->status !== 'pending_payment')
        <p class="card mt-6">This order is {{ str_replace('_', ' ', $order->status) }}. <a class="underline" href="{{ url('/track?number='.$order->number) }}">Track it</a>.</p>
    @else
        <p class="mt-3 text-2xl font-semibold">{{ \App\Support\Money::format($order->amountDue()) }}</p>
        <p class="muted text-sm">Save your order number: <b>{{ $order->number }}</b>. Unpaid orders are cancelled after {{ \App\Support\Settings::int('orders.unpaid_cancel_hours') }} hours.</p>
        <div class="mt-6 grid gap-4">
            @if ($order->receiptUnderReview())
            <div class="card" data-receipt-waiting>
                <h2 class="text-xl">We have your receipt</h2>
                <p class="mt-2 text-sm">We are checking your transfer, usually within a few hours. You don't need to pay again; we send you a message as soon as it is confirmed.</p>
                <a class="btn-ghost mt-4" href="{{ url('/track?number='.$order->number) }}">Track your order</a>
            </div>
            @else
            @feature('payments.manual')
            <div class="card">
                <h2 class="text-xl">Transfer and upload the receipt</h2>
                <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <pre class="rounded-xl bg-mist-100 p-3 whitespace-pre-wrap dark:bg-ink-900">{{ $telebirr }}</pre>
                    <pre class="rounded-xl bg-mist-100 p-3 whitespace-pre-wrap dark:bg-ink-900">{{ $bank }}</pre>
                </div>
                <form class="mt-4 grid gap-3" data-proof-form enctype="multipart/form-data">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="label" for="pm">Paid with</label><select id="pm" name="method" class="input"><option value="telebirr">Telebirr</option><option value="bank_transfer">Bank transfer</option></select></div>
                        <div><label class="label" for="pr">Transaction reference</label><input id="pr" name="reference" class="input"></div>
                    </div>
                    <div><label class="label" for="pa">Paid from (phone / account)</label><input id="pa" name="payer_account" class="input"></div>
                    <div><label class="label" for="pf">Receipt photo or PDF</label><input id="pf" name="proof" type="file" accept="image/*,application/pdf" class="input" required></div>
                    <button class="btn-primary">Send receipt</button>
                </form>
            </div>
            @endfeature
            @endif
            <p data-form-message class="text-sm text-danger-600" role="alert"></p>
        </div>
    @endif
</div>
@endsection
