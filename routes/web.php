<?php

use App\Referral\Attribution;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Referral link: kulmis.market/r/ABDI25 → remembers the code for 30 days.
Route::get('/r/{code}', function (string $code, Attribution $attribution) {
    $attribution->rememberFromLink($code);

    return redirect('/?ref='.rawurlencode(strtoupper($code)));
})->where('code', '[A-Za-z0-9]{2,16}')->middleware('throttle:60,1')->name('referral.link');

// React admin (built into public/admin-app). Any /admin/* path loads the app;
// it talks to /api/v1/admin with the session + CSRF cookie.
Route::get('/admin/{path?}', function () {
    $index = public_path('admin-app/index.html');
    abort_unless(is_file($index), 404, 'Admin not built. Run: cd admin && npm ci && npm run build');

    return response((string) file_get_contents($index), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
})->where('path', '.*')->name('admin.app');
