<?php

use App\Http\Controllers\Admin\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:view-reports')->group(function () {
    Route::get('reports', [ReportController::class, 'index']);
    Route::get('reports/{type}', [ReportController::class, 'show'])->where('type', '[a-z]+')->middleware('throttle:60,1');
});
