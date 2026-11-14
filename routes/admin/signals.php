<?php

use App\Http\Controllers\Admin\SignalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['feature:signals', 'can:manage-signals'])->prefix('signals')->group(function () {
    Route::get('/', [SignalController::class, 'index']);
    Route::post('/', [SignalController::class, 'store']);
    Route::put('{signal}', [SignalController::class, 'update'])->whereNumber('signal');
    Route::post('{signal}/close', [SignalController::class, 'close'])->whereNumber('signal');
    Route::get('subscribers', [SignalController::class, 'subscribers']);
    Route::post('subscribers', [SignalController::class, 'addSubscriber']);
    Route::post('subscribers/{subscription}/remove', [SignalController::class, 'removeSubscriber']);
    Route::get('telegram', [SignalController::class, 'telegramStatus']);
});
