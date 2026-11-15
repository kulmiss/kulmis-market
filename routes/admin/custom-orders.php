<?php

use App\Http\Controllers\Admin\CustomRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['can:manage-orders', 'feature:shop'])->group(function () {
    Route::get('custom-requests', [CustomRequestController::class, 'index'])->middleware('feature:shop.custom_orders');
    Route::post('custom-requests/{custom}/quote', [CustomRequestController::class, 'quote'])->middleware(['feature:shop.custom_orders', 'throttle:30,1']);
    Route::post('custom-requests/{custom}/decline', [CustomRequestController::class, 'decline'])->middleware('feature:shop.custom_orders');
    Route::post('orders/{order}/ask-rest', [CustomRequestController::class, 'askRest'])->middleware('throttle:10,1');
});
