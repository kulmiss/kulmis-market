<?php

use App\Http\Controllers\Api\CatalogController;
use Illuminate\Support\Facades\Route;

/*
| REST API, versioned under /api/v1 (ready for the Flutter app).
*/

Route::name('api.')->group(function () {
    Route::get('/ping', fn () => response()->json([
        'ok' => true,
        'app' => config('app.name'),
        'currency' => config('shop.currency'),
    ]))->name('ping');

    Route::middleware('feature:shop')->group(function () {
        Route::get('products', [CatalogController::class, 'products'])->name('products.index');
        Route::get('products/{slug}', [CatalogController::class, 'product'])->name('products.show');
        Route::get('categories', [CatalogController::class, 'categories'])->name('categories.index');
    });

    foreach (glob(__DIR__.'/api/*.php') ?: [] as $file) {
        require $file;
    }
});
