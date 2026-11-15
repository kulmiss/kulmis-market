<?php

use App\Http\Controllers\Admin\ShareKitController;
use Illuminate\Support\Facades\Route;

Route::middleware(['can:manage-content', 'feature:referral,referral.share_kit'])->prefix('share-kit')->group(function () {
    Route::get('/', [ShareKitController::class, 'index']);
    Route::post('/', [ShareKitController::class, 'save'])->middleware('throttle:30,1');
    Route::post('{item}', [ShareKitController::class, 'save'])->middleware('throttle:30,1');
    Route::delete('{item}', [ShareKitController::class, 'destroy']);
});
