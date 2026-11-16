<?php

use App\Http\Controllers\Admin\HelpController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-content')->prefix('help')->group(function () {
    Route::get('faqs', [HelpController::class, 'faqs']);
    Route::post('faqs', [HelpController::class, 'saveFaq']);
    Route::put('faqs/{faq}', [HelpController::class, 'saveFaq']);
    Route::delete('faqs/{faq}', [HelpController::class, 'deleteFaq']);
});
// Support staff answer messages too.
Route::middleware('can:answer-help')->prefix('help')->group(function () {
    Route::get('messages', [HelpController::class, 'messages']);
    Route::put('messages/{message}', [HelpController::class, 'updateMessage']);
});
