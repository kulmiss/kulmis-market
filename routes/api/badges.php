<?php

use App\Http\Controllers\Api\BadgeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('feature:badges')->group(function () {
        Route::get('badges', [BadgeController::class, 'index'])->name('badges.index');
        Route::post('badges/seen', [BadgeController::class, 'seen'])->middleware('throttle:30,1')->name('badges.seen');
    });
    Route::get('notifications', [BadgeController::class, 'notifications'])->name('notifications.index');
    Route::post('notifications/read', [BadgeController::class, 'readNotifications'])->middleware('throttle:30,1')->name('notifications.read');
});
