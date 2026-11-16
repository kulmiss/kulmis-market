<?php

use App\Http\Controllers\Admin\MoneyHealthController;
use App\Http\Controllers\Admin\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:view-reports')->group(function () {
    Route::get('reports', [ReportController::class, 'index']);
    Route::get('money-health', [MoneyHealthController::class, 'show'])->middleware('throttle:60,1');
    Route::get('money-health/what-if', [MoneyHealthController::class, 'whatIf'])->middleware('throttle:120,1');
    Route::get('reports/{type}', [ReportController::class, 'show'])->where('type', '[a-z]+')->middleware('throttle:60,1');
});
