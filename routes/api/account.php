<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('auth.register');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('me', [AuthController::class, 'me'])->name('me');
});

Route::middleware('feature:membership')->group(function () {
    Route::get('membership/info', [MembershipController::class, 'info'])->name('membership.info');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('membership', [MembershipController::class, 'status'])->name('membership.status');
        Route::post('membership/pay', [MembershipController::class, 'pay'])->middleware('throttle:10,1')->name('membership.pay');
        Route::post('membership/payment-proof', [MembershipController::class, 'proof'])->middleware('throttle:10,1')->name('membership.proof');
    });
});

Route::middleware(['feature:referral', 'auth:sanctum'])->prefix('referral')->name('referral.')->group(function () {
    Route::get('/', [ReferralController::class, 'show'])->name('show');
    Route::get('history', [ReferralController::class, 'history'])->name('history');
    Route::put('code', [ReferralController::class, 'changeCode'])->middleware('throttle:10,1')->name('code');
});

Route::middleware(['feature:wallet', 'auth:sanctum'])->prefix('wallet')->name('wallet.')->group(function () {
    Route::get('/', [WalletController::class, 'show'])->name('show');
    Route::post('cash-out', [WalletController::class, 'cashOut'])
        ->middleware(['feature:wallet.cashout', 'throttle:5,1'])->name('cashout');
});
