<?php

use App\Http\Controllers\Admin\ReferralController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-referrals')->prefix('referrals')->group(function () {
    Route::get('tree/{user}', [ReferralController::class, 'tree']);
    Route::get('commissions', [ReferralController::class, 'commissions']);
    Route::post('members/{user}/referrer', [ReferralController::class, 'changeReferrer']);
});
