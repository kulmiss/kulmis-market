<?php

use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:view-reports')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('status', [DashboardController::class, 'status']);
});
