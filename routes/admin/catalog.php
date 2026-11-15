<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware(['feature:shop', 'can:manage-products'])->group(function () {
    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::post('products/bulk', [ProductController::class, 'bulk']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::put('products/{product}', [ProductController::class, 'update']);
    Route::post('products/{product}/images', [ProductController::class, 'uploadImage']);
    Route::delete('products/{product}/images/{image}', [ProductController::class, 'deleteImage']);
    Route::get('products/{product}/files', [ProductController::class, 'files']);
    Route::post('products/{product}/files', [ProductController::class, 'addFile'])->middleware('throttle:30,1');
    Route::delete('products/{product}/files/{file}', [ProductController::class, 'deleteFile']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::put('categories/{category}', [CategoryController::class, 'update']);
});
