<?php

use App\Http\Controllers\Web\AccountController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\SecurityController;
use App\Http\Controllers\Web\ShopAccountController;
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
    Route::get('/register', [AuthController::class, 'showRegister'])->middleware('feature:accounts.signup')->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware(['feature:accounts.signup', 'throttle:register']);
    Route::get('/forgot', [AuthController::class, 'showForgot'])->name('forgot');
    Route::get('/login/two-factor', [AuthController::class, 'showTwoFactor']);
    Route::post('/login/two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:10,1');
});
Route::get('/account/email/confirm/{token}', [SecurityController::class, 'confirmEmail'])
    ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1')->name('account.email.confirm');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'dashboard'])->name('dashboard');
    Route::get('team', [AccountController::class, 'team'])->name('team');
    Route::get('earnings', [AccountController::class, 'earnings'])->name('earnings');
    Route::get('wallet', [AccountController::class, 'wallet'])->name('wallet');
    Route::get('orders', [AccountController::class, 'orders'])->name('orders');
    Route::get('signals', [AccountController::class, 'signals'])->name('signals');
    Route::post('membership/pay', [AccountController::class, 'payMembership'])->middleware('feature:membership')->name('membership.pay');
    Route::post('membership/proof', [AccountController::class, 'membershipProof'])->middleware(['feature:membership', 'feature:payments.manual'])->name('membership.proof');
    Route::post('cash-out', [AccountController::class, 'cashOut'])->middleware(['feature:wallet', 'feature:wallet.cashout', 'throttle:5,1'])->name('cashout');
    Route::post('payout-account', [AccountController::class, 'payoutAccount'])->middleware(['feature:wallet', 'feature:wallet.cashout', 'throttle:5,10'])->name('payout-account');
    Route::post('referral-code', [AccountController::class, 'referralCode'])->middleware('feature:referral')->name('referral-code');
    Route::get('badges', [AccountController::class, 'badges'])->middleware('feature:badges')->name('badges');
    Route::post('badges/seen', [AccountController::class, 'badgesSeen'])->middleware(['feature:badges', 'throttle:30,1'])->name('badges.seen');

    Route::get('security', [SecurityController::class, 'show'])->name('security');

    // Wishlist, saved addresses, buy again
    Route::middleware('feature:shop.wishlist')->group(function () {
        Route::get('wishlist', [ShopAccountController::class, 'wishlist'])->name('wishlist');
        Route::post('wishlist/{product}', [ShopAccountController::class, 'toggleWishlist'])->middleware('throttle:60,1')->name('wishlist.toggle');
    });
    Route::middleware('feature:shop.saved_addresses')->group(function () {
        Route::get('addresses', [ShopAccountController::class, 'addresses'])->name('addresses');
        Route::post('addresses', [ShopAccountController::class, 'saveAddress'])->middleware('throttle:20,1')->name('addresses.store');
        Route::put('addresses/{address}', [ShopAccountController::class, 'saveAddress'])->middleware('throttle:20,1')->name('addresses.update');
        Route::delete('addresses/{address}', [ShopAccountController::class, 'deleteAddress'])->middleware('throttle:20,1')->name('addresses.delete');
    });
    Route::post('orders/{number}/buy-again', [ShopAccountController::class, 'buyAgain'])->middleware(['feature:shop', 'feature:shop.buy_again', 'throttle:20,1'])->name('orders.buy-again');
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('security/password', [SecurityController::class, 'password'])->name('security.password');
        Route::post('security/email', [SecurityController::class, 'email'])->name('security.email');
        Route::post('security/two-factor', [SecurityController::class, 'twoFactorBegin'])->middleware('feature:accounts.two_factor')->name('security.2fa.begin');
        Route::post('security/two-factor/confirm', [SecurityController::class, 'twoFactorConfirm'])->middleware('feature:accounts.two_factor')->name('security.2fa.confirm');
        Route::post('security/two-factor/disable', [SecurityController::class, 'twoFactorDisable'])->name('security.2fa.disable');
        Route::post('security/sign-out-everywhere', [SecurityController::class, 'signOutEverywhere'])->name('security.signout');
    });
});
Route::post('/checkout', [AccountController::class, 'checkout'])->middleware(['auth', 'feature:shop', 'throttle:10,1'])->name('checkout.member');

Route::get('/account/referral-qr.svg', function (Request $request, Engagement $engagement) {
    app(ReferralCodes::class)->assign($request->user());

    return response($engagement->qrSvg(url('/r/'.$request->user()->referral_code)), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=86400']);
})->middleware(['auth', 'feature:referral', 'feature:referral.qr_codes'])->name('account.qr');
