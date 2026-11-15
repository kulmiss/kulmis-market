<?php

use App\Http\Controllers\Admin\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:view-payments')->group(function () {
    Route::get('payments', [PaymentController::class, 'index']);
    Route::get('payments/{payment}', [PaymentController::class, 'show']);
    Route::get('payments/{payment}/proof', [PaymentController::class, 'proof']);
    Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware('feature:payments.pdf_receipts');
});
Route::middleware('can:approve-payments')->group(function () {
    Route::post('payments/{payment}/approve', [PaymentController::class, 'approve']);
    Route::post('payments/{payment}/reject', [PaymentController::class, 'reject']);
});
Route::middleware('can:manage-ledger')->post('payments/{payment}/refund', [PaymentController::class, 'refund']);
