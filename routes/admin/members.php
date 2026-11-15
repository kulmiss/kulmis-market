<?php

use App\Http\Controllers\Admin\MemberController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-members')->group(function () {
    Route::get('members', [MemberController::class, 'index']);
    Route::get('members/{user}', [MemberController::class, 'show']);
    Route::post('members/{user}/membership', [MemberController::class, 'adjust'])->middleware('feature:membership');
    Route::post('members/{user}/status', [MemberController::class, 'status']);
});
