<?php

use App\Http\Controllers\Admin\CustomRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-orders')->group(function () {
    Route::get('custom-requests', [CustomRequestController::class, 'index']);
    Route::post('custom-requests/{custom}/quote', [CustomRequestController::class, 'quote'])->middleware(['throttle:30,1']);
    Route::post('custom-requests/{custom}/decline', [CustomRequestController::class, 'decline']);
    Route::post('orders/{order}/ask-rest', [CustomRequestController::class, 'askRest'])->middleware('throttle:10,1');
});
