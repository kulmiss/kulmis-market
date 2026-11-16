<?php

use App\Http\Controllers\Admin\CashOutController;
use App\Http\Controllers\Admin\FraudController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:approve-cashouts')->group(function () {
    Route::get('cash-outs', [CashOutController::class, 'index']);
    Route::get('cash-outs/{payout}', [CashOutController::class, 'show']);
    Route::post('cash-outs/{payout}/pay', [CashOutController::class, 'pay']);
    Route::post('cash-outs/{payout}/reject', [CashOutController::class, 'reject']);
});

Route::middleware('can:review-fraud')->group(function () {
    Route::get('fraud', [FraudController::class, 'index']);
    Route::post('fraud/{flag}/review', [FraudController::class, 'review']);
    Route::post('fraud/users/{user}', [FraudController::class, 'flag']);
});
