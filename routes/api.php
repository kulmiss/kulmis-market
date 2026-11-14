<?php

use Illuminate\Support\Facades\Route;

/*
| REST API, versioned under /api/v1 (ready for the Flutter app).
| Resource endpoints arrive with their feature PRs; auth (Sanctum) in PR 14.
*/

Route::get('/ping', fn () => response()->json([
    'ok' => true,
    'app' => config('app.name'),
    'currency' => config('shop.currency'),
]))->middleware('throttle:60,1');
