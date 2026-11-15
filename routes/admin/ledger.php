<?php

use App\Http\Controllers\Admin\LedgerController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-ledger')->prefix('ledger')->group(function () {
    Route::get('entries', [LedgerController::class, 'entries']);
    Route::get('accounts', [LedgerController::class, 'accounts']);
    Route::get('wallets/{user}', [LedgerController::class, 'wallet']);
    Route::post('adjustments', [LedgerController::class, 'adjust']);
});
