<?php

use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CustomRequestController;
use App\Http\Controllers\Api\GiftCardController;
use App\Http\Controllers\Api\GiftController;
use App\Http\Controllers\Api\HelpFeedbackController;
use App\Http\Controllers\Api\StockAlertController;
use App\Http\Controllers\Api\TrackingController;
use App\Models\ShippingZone;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Route;

Route::middleware(['feature:shop', EncryptCookies::class, AddQueuedCookiesToResponse::class])->group(function () {
    Route::get('cart', [CartController::class, 'show'])->name('cart.show');
    Route::post('cart/items', [CartController::class, 'add'])->middleware('throttle:60,1')->name('cart.add');
    Route::post('cart/bundles/{bundle}', [CartController::class, 'addBundle'])->whereNumber('bundle')->middleware(['throttle:30,1', 'feature:shop.bundles'])->name('cart.bundle');
    Route::patch('cart/items/{item}', [CartController::class, 'update'])->whereNumber('item')->middleware('throttle:60,1')->name('cart.update');
    Route::put('cart/gift-card', [CartController::class, 'giftCard'])->middleware('throttle:10,1')->name('cart.gift-card');
    Route::put('cart/extras', [CartController::class, 'extras'])->middleware('throttle:30,1')->name('cart.extras');
    // Coupon guessing is slow: 10 tries a minute.
    Route::put('cart/coupon', [CartController::class, 'coupon'])->middleware('throttle:10,1')->name('cart.coupon');
    Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout');
    Route::post('orders/track', TrackingController::class)->middleware(['feature:shop.order_tracking', 'throttle:10,1'])->name('orders.track');
});

Route::middleware('feature:shop')->get('shipping/zones', fn () => response()->json([
    'data' => ShippingZone::query()->where('is_active', true)->orderBy('position')
        ->get(['id', 'name', 'cities', 'base_fee', 'included_grams', 'per_kg_fee', 'eta_days_min', 'eta_days_max', 'is_default']),
]))->name('shipping.zones');

Route::post('custom-requests', [CustomRequestController::class, 'store'])->middleware(['feature:shop,shop.custom_orders', 'throttle:3,10'])->name('custom-requests.store');
Route::post('gift-cards', [GiftCardController::class, 'store'])->middleware(['feature:shop,shop.gift_cards', 'throttle:5,10'])->name('gift-cards.store');
Route::post('gifts', [GiftController::class, 'store'])->middleware(['feature:membership,membership.gifts', 'throttle:5,10'])->name('gifts.store');
Route::post('help/{slug}/feedback', [HelpFeedbackController::class, 'store'])->where('slug', '[a-z0-9-]{1,80}')
    ->middleware(['feature:site.help_centre', 'feature:site.help_feedback', 'throttle:20,1'])->name('help.feedback');
Route::post('contact', [ContactController::class, 'store'])->middleware(['feature:site.help_centre', 'throttle:5,10'])->name('contact');
Route::post('stock-alerts', [StockAlertController::class, 'store'])->middleware(['feature:shop.stock_alerts', 'throttle:5,1'])->name('stock-alerts');
