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
