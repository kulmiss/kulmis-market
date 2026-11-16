<?php

use App\Http\Controllers\Admin\BundleController;
use App\Http\Controllers\Admin\FlashSaleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['can:manage-products', 'feature:shop,shop.flash_sales'])->group(function () {
    Route::get('flash-sales', [FlashSaleController::class, 'index']);
    Route::get('flash-sales/check', [FlashSaleController::class, 'check']);
    Route::post('flash-sales', [FlashSaleController::class, 'store']);
    Route::put('flash-sales/{flashSale}', [FlashSaleController::class, 'update']);
    Route::delete('flash-sales/{flashSale}', [FlashSaleController::class, 'destroy']);
});

Route::middleware(['can:manage-products', 'feature:shop,shop.bundles'])->group(function () {
    Route::get('bundles', [BundleController::class, 'index']);
    Route::post('bundles/check', [BundleController::class, 'check']);
    Route::post('bundles', [BundleController::class, 'store']);
    Route::put('bundles/{bundle}', [BundleController::class, 'update']);
    Route::delete('bundles/{bundle}', [BundleController::class, 'destroy']);
});
