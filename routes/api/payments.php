<?php

use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['feature:shop', 'throttle:20,1'])->group(function () {
    Route::post('orders/{number}/payment-options', [PaymentController::class, 'options'])->name('orders.payment-options');
    Route::post('orders/{number}/payment-proof', [PaymentController::class, 'proof'])->name('orders.payment-proof');
});
Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware('signed')->name('payments.receipt');
