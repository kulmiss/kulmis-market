<?php

use App\Http\Controllers\Admin\HomePageController;
use Illuminate\Support\Facades\Route;

// Admin → Home page (the home page builder). Super Admin and the Content editor role.
Route::middleware('can:manage-home')->prefix('home-page')->group(function () {
    Route::get('/', [HomePageController::class, 'show']);
    Route::put('draft', [HomePageController::class, 'saveDraft'])->middleware('throttle:120,1');
    Route::delete('draft', [HomePageController::class, 'discard']);
    Route::post('preview', [HomePageController::class, 'preview'])->middleware('throttle:120,1');
    Route::post('publish', [HomePageController::class, 'publish'])->middleware('throttle:20,1');
    Route::post('schedule', [HomePageController::class, 'schedule'])->middleware('throttle:20,1');
    Route::delete('schedule', [HomePageController::class, 'cancelSchedule']);
    Route::get('versions', [HomePageController::class, 'versions']);
    Route::get('versions/{version}/preview', [HomePageController::class, 'versionPreview'])->middleware('throttle:60,1');
    Route::post('versions/{version}/restore', [HomePageController::class, 'restore'])->middleware('throttle:20,1');
    Route::get('templates/{key}', [HomePageController::class, 'template'])->where('key', '[a-z_]{1,40}');
    Route::get('sections/new/{type}', [HomePageController::class, 'newSection'])->where('type', '[a-z_]{1,40}');
    Route::get('options/{kind}', [HomePageController::class, 'options'])->whereIn('kind', ['categories', 'products', 'faqs']);
});
