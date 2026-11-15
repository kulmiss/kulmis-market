<?php

use App\Http\Controllers\Api\SignalController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/telegram', [SignalController::class, 'telegram'])->middleware('throttle:300,1')->name('webhooks.telegram');

Route::middleware('feature:signals')->prefix('signals')->name('signals.')->group(function () {
    Route::get('info', [SignalController::class, 'info'])->name('info');
    Route::get('performance', [SignalController::class, 'performance'])->middleware('feature:signals.performance_page')->name('performance');
    Route::post('payment-proof', [SignalController::class, 'proof'])->middleware('throttle:10,1')->name('proof');
    Route::get('member-access', [SignalController::class, 'memberAccess'])->middleware(['auth:sanctum', 'feature:membership'])->name('member-access');
});
