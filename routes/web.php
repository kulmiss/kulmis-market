<?php

use App\Http\Controllers\Web\AccountController;
use App\Http\Controllers\Web\AuthController;
use App\Referral\Engagement;
use App\Referral\ReferralCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Pages that need a session (sign-in, member area, member checkout).
| Public pages live in routes/storefront.php (no session for guests).
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::get('/forgot', [AuthController::class, 'showForgot'])->name('forgot');
});
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'dashboard'])->name('dashboard');
    Route::post('membership/pay', [AccountController::class, 'payMembership'])->middleware('feature:membership')->name('membership.pay');
    Route::post('membership/proof', [AccountController::class, 'membershipProof'])->middleware(['feature:membership', 'feature:payments.manual'])->name('membership.proof');
    Route::post('cash-out', [AccountController::class, 'cashOut'])->middleware(['feature:wallet', 'feature:wallet.cashout', 'throttle:5,1'])->name('cashout');
    Route::post('referral-code', [AccountController::class, 'referralCode'])->middleware('feature:referral')->name('referral-code');
});
Route::post('/checkout', [AccountController::class, 'checkout'])->middleware(['auth', 'feature:shop', 'throttle:10,1'])->name('checkout.member');

Route::get('/account/referral-qr.svg', function (Request $request, Engagement $engagement) {
    app(ReferralCodes::class)->assign($request->user());

    return response($engagement->qrSvg(url('/r/'.$request->user()->referral_code)), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=86400']);
})->middleware(['auth', 'feature:referral'])->name('account.qr');
