<?php

use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\Route;

/*
| Admin API for the React admin (same-domain session + CSRF cookie).
| Mounted at /api/v1/admin with the `web` middleware group.
| Every admin route below `admin.2fa` requires a 2FA-verified session.
*/

Route::post('auth/login', [Admin\AuthController::class, 'login'])->middleware('throttle:login');
Route::post('auth/two-factor', [Admin\AuthController::class, 'twoFactor'])->middleware('throttle:two-factor');

Route::middleware(['auth', 'staff', 'admin.timeout'])->group(function () {
    Route::post('auth/logout', [Admin\AuthController::class, 'logout']);
    Route::post('auth/sign-out-others', [Admin\AuthController::class, 'signOutOthers']);
    Route::get('auth/me', [Admin\AuthController::class, 'me']);
    Route::post('two-factor/setup', [Admin\TwoFactorController::class, 'setup']);
    Route::post('two-factor/confirm', [Admin\TwoFactorController::class, 'confirm']);

    Route::middleware('admin.2fa')->group(function () {
        Route::delete('two-factor', [Admin\TwoFactorController::class, 'disable']);

        Route::get('features', [Admin\FeatureController::class, 'index'])->can('manage-features');
        Route::put('features', [Admin\FeatureController::class, 'update'])->can('manage-features');
        Route::get('settings', [Admin\SettingController::class, 'index'])->can('manage-settings');
        Route::put('settings', [Admin\SettingController::class, 'update'])->can('manage-settings');
        Route::get('badges', [Admin\BadgeController::class, 'index'])->can('manage-settings');
        Route::post('badges', [Admin\BadgeController::class, 'store'])->can('manage-settings');
        Route::put('badges/{badge}', [Admin\BadgeController::class, 'update'])->can('manage-settings');
        Route::delete('badges/{badge}', [Admin\BadgeController::class, 'destroy'])->can('manage-settings');
        Route::get('activity', [Admin\ActivityLogController::class, 'index'])->can('view-activity');

        foreach (glob(__DIR__.'/admin/*.php') ?: [] as $file) {
            require $file;
        }
    });
});
