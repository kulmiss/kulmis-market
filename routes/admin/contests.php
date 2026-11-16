<?php

use App\Http\Controllers\Admin\ContestController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-referrals')->group(function () {
    Route::get('contests', [ContestController::class, 'index']);
    Route::post('contests', [ContestController::class, 'store']);
    Route::put('contests/{contest}', [ContestController::class, 'update']);
    Route::post('contests/{contest}/cancel', [ContestController::class, 'cancel']);
    Route::post('challenges', [ContestController::class, 'saveChallenge']);
    Route::put('challenges/{challenge}', [ContestController::class, 'saveChallenge']);
});
