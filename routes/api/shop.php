<?php

use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\StockAlertController;
use App\Http\Controllers\Api\TrackingController;
use App\Models\ShippingZone;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Route;

Route::middleware(['feature:shop', EncryptCookies::class, AddQueuedCookiesToResponse::class])->group(function () {
    Route::get('cart', [CartController::class, 'show'])->name('cart.show');
    Route::post('cart/items', [CartController::class, 'add'])->middleware('throttle:60,1')->name('cart.add');
    Route::patch('cart/items/{item}', [CartController::class, 'update'])->whereNumber('item')->middleware('throttle:60,1')->name('cart.update');
    // Coupon guessing is slow: 10 tries a minute.
    Route::put('cart/coupon', [CartController::class, 'coupon'])->middleware('throttle:10,1')->name('cart.coupon');
    Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout');
    Route::post('orders/track', TrackingController::class)->middleware(['feature:shop.order_tracking', 'throttle:10,1'])->name('orders.track');
});

Route::middleware('feature:shop')->get('shipping/zones', fn () => response()->json([
    'data' => ShippingZone::query()->where('is_active', true)->orderBy('position')
        ->get(['id', 'name', 'cities', 'base_fee', 'included_grams', 'per_kg_fee', 'eta_days_min', 'eta_days_max', 'is_default']),
]))->name('shipping.zones');

Route::post('stock-alerts', [StockAlertController::class, 'store'])->middleware(['feature:shop.stock_alerts', 'throttle:5,1'])->name('stock-alerts');
