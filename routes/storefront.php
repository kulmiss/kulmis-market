<?php

use App\Http\Controllers\Web\StorefrontController as S;
use App\Referral\Attribution;
use Illuminate\Support\Facades\Route;

/*
| Public pages: no session for guests (no cookie, no IP), cached as static HTML.
| Middleware group "storefront" (see bootstrap/app.php).
*/

Route::get('/', [S::class, 'home'])->name('home');
Route::get('/shop', [S::class, 'shop'])->name('shop');
Route::get('/c/{category}', [S::class, 'shop'])->where('category', '[a-z0-9-]+')->name('category');
Route::get('/shop/{slug}', [S::class, 'product'])->where('slug', '[a-z0-9-]+')->name('product');
Route::get('/membership', [S::class, 'membership'])->name('membership');
Route::get('/referral-program', [S::class, 'referralProgram'])->name('referral-program');
Route::get('/signals', [S::class, 'signals'])->name('signals');
Route::get('/signals/performance', [S::class, 'performance'])->name('signals.performance');
Route::get('/p/{slug}', [S::class, 'page'])->where('slug', '[a-z0-9-]+')->name('page');
Route::get('/cart', [S::class, 'cart'])->name('cart');
Route::get('/checkout', [S::class, 'checkout'])->name('checkout');
Route::get('/pay/{number}', [S::class, 'pay'])->name('pay');
Route::get('/track', [S::class, 'track'])->name('track');
Route::get('/sitemap.xml', [S::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [S::class, 'robots'])->name('robots');

// Referral link: /r/ABDI25 → remembers the code for 30 days (essential cookie, code only).
Route::get('/r/{code}', function (string $code, Attribution $attribution) {
    $attribution->rememberFromLink($code);

    return redirect('/?ref='.rawurlencode(strtoupper($code)));
})->where('code', '[A-Za-z0-9]{2,16}')->middleware('throttle:60,1')->name('referral.link');

// React admin (built into public/admin-app). Any /admin/* path loads the app.
Route::get('/admin/{path?}', function () {
    $index = public_path('admin-app/index.html');
    abort_unless(is_file($index), 404, 'Admin not built. Run: cd admin && npm ci && npm run build');

    return response((string) file_get_contents($index), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
})->where('path', '.*')->name('admin.app');
