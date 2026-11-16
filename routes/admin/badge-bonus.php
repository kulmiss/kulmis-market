<?php

use App\Http\Controllers\Admin\BadgeBonusController;
use Illuminate\Support\Facades\Route;

// Super Admin: budget, forecast, dry run, pay now / skip, history.
Route::middleware('can:manage-settings')->prefix('badge-bonus')->group(function () {
    Route::get('/', [BadgeBonusController::class, 'show']);
    Route::get('months/{month}', [BadgeBonusController::class, 'month'])->where('month', '\d{4}-\d{2}');
    Route::post('pay', [BadgeBonusController::class, 'pay'])->middleware('throttle:10,1');
    Route::post('skip', [BadgeBonusController::class, 'skip'])->middleware('throttle:10,1');
});
