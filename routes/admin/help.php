<?php

use App\Http\Controllers\Admin\HelpArticleController;
use App\Http\Controllers\Admin\HelpController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:manage-content')->prefix('help')->group(function () {
    Route::get('faqs', [HelpController::class, 'faqs']);
    Route::post('faqs', [HelpController::class, 'saveFaq']);
    Route::put('faqs/{faq}', [HelpController::class, 'saveFaq']);
    Route::delete('faqs/{faq}', [HelpController::class, 'deleteFaq']);
    Route::get('articles', [HelpArticleController::class, 'index']);
    Route::post('articles/preview', [HelpArticleController::class, 'preview'])->middleware('throttle:120,1');
    Route::post('articles', [HelpArticleController::class, 'save']);
    Route::put('articles/{slug}', [HelpArticleController::class, 'save'])->where('slug', '[a-z0-9-]{1,80}');
    Route::delete('articles/{slug}', [HelpArticleController::class, 'destroy'])->where('slug', '[a-z0-9-]{1,80}');
});
// Support staff answer messages too.
Route::middleware('can:answer-help')->prefix('help')->group(function () {
    Route::get('messages', [HelpController::class, 'messages']);
    Route::put('messages/{message}', [HelpController::class, 'updateMessage']);
});
