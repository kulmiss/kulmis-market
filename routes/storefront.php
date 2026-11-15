<?php

use App\Http\Controllers\Web\GiftController;
use App\Http\Controllers\Web\HelpController;
use App\Http\Controllers\Web\StorefrontController as S;
use App\Http\Middleware\AdminIpAllowlist;
use App\Referral\Attribution;
use App\Referral\ShareLinks;
use App\Shop\GiftCards;
use App\Support\Features;
use App\Support\Settings;
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
Route::get('/help', [HelpController::class, 'index'])->name('help');
Route::get('/gift-cards', fn () => Features::enabled('shop') && Features::enabled('shop.gift_cards')
    ? view('store.gift-cards', ['amounts' => GiftCards::amounts(), 'bank' => Settings::string('payments.bank_details'),
        'telebirr' => Settings::string('payments.telebirr_details')]) : abort(404))->name('gift-cards');
Route::get('/membership/gift', [GiftController::class, 'form'])->name('membership.gift');
Route::get('/p/{slug}', [S::class, 'page'])->where('slug', '[a-z0-9-]+')->name('page');
Route::get('/cart', [S::class, 'cart'])->name('cart');
Route::get('/checkout', [S::class, 'checkout'])->name('checkout');
Route::get('/pay/{number}', [S::class, 'pay'])->name('pay');
Route::get('/track', [S::class, 'track'])->name('track');
Route::get('/sitemap.xml', [S::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [S::class, 'robots'])->name('robots');
// RFC 9116: how to report a security problem.
Route::get('/.well-known/security.txt', fn () => response(implode("\n", [
    'Contact: mailto:'.Settings::string('site.support_email'),
    'Expires: '.now()->addYear()->startOfMonth()->toIso8601ZuluString(),
    'Preferred-Languages: en, am',
    'Canonical: '.url('/.well-known/security.txt'),
    'Policy: '.url('/p/terms'),
])."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=86400']))->name('security.txt');

// Referral link: /r/ABDI25 → remembers the code for 30 days (essential cookie, code only).
Route::get('/r/{code}', function (string $code, Attribution $attribution) {
    $attribution->rememberFromLink($code);

    return redirect('/?ref='.rawurlencode(strtoupper($code)));
})->where('code', '[A-Za-z0-9]{2,16}')->middleware('throttle:60,1')->name('referral.link');
// Share links to a page: /r/CODE/membership, /r/CODE/signals, /r/CODE/shop/<product>.
Route::get('/r/{code}/{to}', function (string $code, string $to, Attribution $attribution) {
    $target = ShareLinks::target($to);
    abort_if($target === null, 404);
    $attribution->rememberFromLink($code);

    return redirect($target.'?ref='.rawurlencode(strtoupper($code)));
})->where(['code' => '[A-Za-z0-9]{2,16}', 'to' => '(membership|signals|shop/[a-z0-9-]{1,160})'])->middleware('throttle:60,1')->name('referral.link.to');

// React admin (built into public/admin-app). Any /admin/* path loads the app.
Route::get('/admin/{path?}', function () {
    $index = public_path('admin-app/index.html');
    abort_unless(is_file($index), 404, 'Admin not built. Run: cd admin && npm ci && npm run build');

    return response((string) file_get_contents($index), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
})->where('path', '.*')->name('admin.app')->middleware(AdminIpAllowlist::class);
