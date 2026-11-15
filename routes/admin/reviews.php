<?php

use App\Http\Controllers\Admin\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['can:manage-products', 'feature:shop.reviews'])->prefix('reviews')->group(function () {
    Route::get('/', [ReviewController::class, 'index']);
    Route::post('{review}/approve', [ReviewController::class, 'approve'])->middleware('throttle:60,1');
    Route::post('{review}/reject', [ReviewController::class, 'reject'])->middleware('throttle:60,1');
});
