<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:register')->name('auth.register');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
Route::post('auth/two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:10,1')->name('auth.two-factor');

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
    Route::get('qr.svg', [ReferralController::class, 'qr'])->name('qr');
});

// Public monthly list: first name + badge only.
Route::middleware(['feature:referral', 'feature:top_referrers'])->get('referral/top', [ReferralController::class, 'top'])->name('referral.top');

Route::middleware(['feature:wallet', 'auth:sanctum'])->prefix('wallet')->name('wallet.')->group(function () {
    Route::get('/', [WalletController::class, 'show'])->name('show');
    Route::post('cash-out', [WalletController::class, 'cashOut'])
        ->middleware(['feature:wallet.cashout', 'throttle:5,1'])->name('cashout');
    Route::put('payout-account', [WalletController::class, 'savePayoutAccount'])
        ->middleware(['feature:wallet.cashout', 'throttle:5,10'])->name('payout-account');
});

Route::post('auth/forgot', [AccountController::class, 'forgot'])->middleware('throttle:3,10')->name('auth.forgot');
Route::post('auth/reset', [AccountController::class, 'reset'])->middleware('throttle:10,10')->name('auth.reset');

Route::middleware('auth:sanctum')->prefix('me')->name('me.')->group(function () {
    Route::put('/', [AccountController::class, 'update'])->middleware('throttle:10,1')->name('update');
    Route::put('password', [AccountController::class, 'password'])->middleware('throttle:10,1')->name('password');
    Route::post('sign-out-all', [AccountController::class, 'signOutAll'])->name('sign-out-all');
    Route::post('two-factor', [AccountController::class, 'twoFactorSetup'])->middleware('throttle:10,1')->name('2fa.setup');
    Route::post('two-factor/confirm', [AccountController::class, 'twoFactorConfirm'])->middleware('throttle:10,1')->name('2fa.confirm');
    Route::delete('two-factor', [AccountController::class, 'twoFactorDisable'])->middleware('throttle:10,1')->name('2fa.disable');
    Route::get('orders', [AccountController::class, 'orders'])->name('orders');
    Route::get('orders/{number}', [AccountController::class, 'order'])->name('orders.show');
});
