<?php

use App\Http\Controllers\Admin\ContestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['can:manage-referrals', 'feature:referral'])->group(function () {
    Route::get('contests', [ContestController::class, 'index']);
    Route::post('contests', [ContestController::class, 'store'])->middleware('feature:referral.contests');
    Route::put('contests/{contest}', [ContestController::class, 'update'])->middleware('feature:referral.contests');
    Route::post('contests/{contest}/cancel', [ContestController::class, 'cancel'])->middleware('feature:referral.contests');
    Route::post('challenges', [ContestController::class, 'saveChallenge'])->middleware('feature:referral.challenges');
    Route::put('challenges/{challenge}', [ContestController::class, 'saveChallenge'])->middleware('feature:referral.challenges');
});
