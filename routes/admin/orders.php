<?php

use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ShippingController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-orders')->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders/bulk-status', [OrderController::class, 'bulkStatus']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders/{order}/status', [OrderController::class, 'status']);
    Route::put('orders/{order}/shipping', [OrderController::class, 'shipping']);
    Route::get('couriers', [OrderController::class, 'couriers']);

    Route::get('shipping-zones', [ShippingController::class, 'zones']);
    Route::post('shipping-zones', [ShippingController::class, 'storeZone']);
    Route::put('shipping-zones/{zone}', [ShippingController::class, 'updateZone']);
    Route::post('couriers', [ShippingController::class, 'storeCourier']);
    Route::put('couriers/{courier}', [ShippingController::class, 'updateCourier']);
});
