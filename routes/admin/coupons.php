<?php

use App\Http\Controllers\Admin\CouponController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-products')->group(function () {
    Route::get('coupons', [CouponController::class, 'index']);
    Route::post('coupons', [CouponController::class, 'store']);
    Route::put('coupons/{coupon}', [CouponController::class, 'update']);
});
