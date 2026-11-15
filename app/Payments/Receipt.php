<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class Receipt
{
    public function download(Payment $payment): Response
    {
        abort_unless($payment->isPaid(), 404);
        $payable = $payment->payable;
        $pdf = Pdf::loadView('pdf.receipt', [
            'payment' => $payment,
            'payable' => $payable,
            'order' => $payable instanceof Order ? $payable->load('items') : null,
            'customer' => $payable->payableCustomer(),
            'title' => $payable->payableTitle(),
        ])->setPaper('a5');

        return $pdf->download('receipt-'.$payment->tx_ref.'.pdf');
    }

    /** A link the customer can open without an account (valid 30 days). */
    public static function link(Payment $payment): string
    {
        return URL::temporarySignedRoute('api.payments.receipt', now()->addDays(30), ['payment' => $payment->uuid]);
    }
}
