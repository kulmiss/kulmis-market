<?php

use App\Http\Controllers\Admin\ContentController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-content')->group(function () {
    Route::get('pages', [ContentController::class, 'pages']);
    Route::post('pages', [ContentController::class, 'savePage']);
    Route::put('pages/{page}', [ContentController::class, 'savePage']);
    Route::get('banners', [ContentController::class, 'banners']);
    Route::post('banners', [ContentController::class, 'saveBanner']);
    Route::put('banners/{banner}', [ContentController::class, 'saveBanner']);
    Route::delete('banners/{banner}', [ContentController::class, 'deleteBanner']);
});
