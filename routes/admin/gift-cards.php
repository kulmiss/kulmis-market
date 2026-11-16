<?php

use App\Http\Controllers\Admin\GiftCardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['can:manage-orders', 'feature:shop,shop.gift_cards'])->prefix('gift-cards')->group(function () {
    Route::get('/', [GiftCardController::class, 'index']);
    // Selling for cash creates money to spend: only people who confirm money received (not fulfilment/support).
    Route::post('sell', [GiftCardController::class, 'sell'])->middleware(['can:approve-payments', 'throttle:20,1']);
    Route::post('{card}/disable', [GiftCardController::class, 'disable']);
});
