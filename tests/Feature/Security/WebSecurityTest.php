<?php

namespace Tests\Feature\Security;

use App\Console\Commands\PruneLogs;
use App\Fraud\FraudGuard;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Logging\RedactSecrets;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Payments\ProofStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Referral\ReferralTestCase;

class WebSecurityTest extends ReferralTestCase
{
    public function test_security_headers_everywhere_and_no_store_on_private_pages(): void
    {
        $r = $this->get('/');
        foreach (['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin', 'X-XSS-Protection' => '0', 'Referrer-Policy' => 'strict-origin-when-cross-origin'] as $h => $v) {
            $r->assertHeader($h, $v);
        }
        $r->assertHeaderMissing('X-Powered-By');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $r->headers->get('Content-Security-Policy'));

        $this->actingAs(User::factory()->create(), 'web')->get('/account')->assertHeader('Cache-Control', 'no-store, private');
        $this->app['auth']->forgetGuards();
        $this->actingAsAdmin();
        $this->assertStringContainsString('no-store', (string) $this->getJson('/api/v1/admin/auth/me')->headers->get('Cache-Control'));
    }

    public function test_https_sites_get_hsts_secure_cookies_and_upgrade_insecure_requests(): void
    {
        config(['app.url' => 'https://kulmis.market', 'session.secure' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $r = $this->get('https://kulmis.market/login');
        $r->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->assertStringContainsString('upgrade-insecure-requests', (string) $r->headers->get('Content-Security-Policy'));
        $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === 'km_session');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_cors_only_allows_our_own_site(): void
    {
        $evil = $this->call('OPTIONS', '/api/v1/products', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);
        // Only our own origin is ever named, so the browser blocks the other site.
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $evil->headers->get('Access-Control-Allow-Origin'));

        $own = rtrim((string) config('app.url'), '/');
        $ok = $this->call('OPTIONS', '/api/v1/products', [], [], [], ['HTTP_ORIGIN' => $own, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);
        $this->assertSame($own, $ok->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($ok->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_security_txt(): void
    {
        $r = $this->get('/.well-known/security.txt')->assertOk();
        $this->assertStringContainsString('Contact: mailto:', $r->getContent());
        $this->assertMatchesRegularExpression('/Expires: \d{4}-\d\d-\d\dT/', $r->getContent());
    }

    public function test_every_website_form_needs_a_csrf_token(): void
    {
        $routes = array_filter(app('router')->getRoutes()->getRoutes(), fn (Route $r) => in_array('POST', $r->methods(), true)
            && ! str_starts_with($r->uri(), 'api/') && collect($r->gatherMiddleware())->contains(fn ($m) => in_array($m, ['web', 'storefront'], true)));
        $this->assertGreaterThan(10, count($routes));
        $user = User::factory()->create();
        // Laravel skips the CSRF check while unit tests run; switch it back on here.
        $this->app->detectEnvironment(fn () => 'local');
        foreach ($routes as $r) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, 'web');
            $status = $this->post('/'.preg_replace('/\{[^}]+\}/', '1', $r->uri()))->status();
            $this->assertContains($status, [419, 302], 'POST '.$r->uri()." without a CSRF token answered $status");
            if ($status === 302) {
                $this->fail('POST '.$r->uri().' accepted a request without a CSRF token');
            }
        }
        // The admin API (session-based) needs it too.
        $this->app['auth']->forgetGuards();
        $this->actingAsAdmin();
        $this->call('PUT', '/api/v1/admin/settings', ['settings' => []], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(419);
    }

    public function test_coupon_guessing_is_rate_limited(): void
    {
        $p = Product::factory()->create(['stock' => 5]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        foreach (range(1, 10) as $i) {
            $this->withHeaders(['X-Cart-Token' => $token])->putJson('/api/v1/cart/coupon', ['code' => 'GUESS'.$i])->assertUnprocessable();
        }
        $this->withHeaders(['X-Cart-Token' => $token])->putJson('/api/v1/cart/coupon', ['code' => 'GUESS11'])->assertStatus(429);
    }

    public function test_each_route_has_its_own_rate_limit_counter(): void
    {
        $p = Product::factory()->create(['stock' => 999]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        // 10 tracking lookups (limit 10/min) must not use up the checkout's 10/min.
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/v1/orders/track', ['number' => 'KM000000-0000'.$i, 'phone' => '0911000000']);
        }
        $this->postJson('/api/v1/orders/track', ['number' => 'KM000000-00011', 'phone' => '0911000000'])->assertStatus(429);
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'H', 'phone' => '0911000999', 'city' => 'Adama', 'address' => 'x'])->assertCreated();
    }

    // ── Uploads ───────────────────────────────────────────────

    private function jpegWithHiddenData(): string
    {
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        // Insert an EXIF APP1 segment with a GPS marker after SOI, and append a PHP payload.
        $exif = "Exif\0\0".'GPSLatitude=9.0108;GPSLongitude=38.7613';
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2).'<?php system($_GET["c"]); ?>';
    }

    public function test_photos_are_redrawn_without_hidden_data(): void
    {
        $file = UploadedFile::fake()->createWithContent('receipt.jpg', $this->jpegWithHiddenData());
        $path = app(ProofStorage::class)->store($file);
        $stored = Storage::disk('local')->get($path);

        $this->assertStringEndsWith('.jpg', $path);
        $this->assertStringStartsWith('payment-proofs/', $path);
        $this->assertStringNotContainsString('GPSLatitude', (string) $stored);
        $this->assertStringNotContainsString('<?php', (string) $stored);
        $this->assertNotFalse(getimagesizefromstring((string) $stored));
    }

    public function test_fake_images_and_scripts_are_refused_and_real_pdfs_kept(): void
    {
        foreach (['<html><script>alert(1)</script></html>', '<?php echo 1;', 'GIF89a<script>', "\x89PNG\r\n\x1a\nnot really"] as $bad) {
            try {
                app(ProofStorage::class)->store(UploadedFile::fake()->createWithContent('x.png', $bad));
                $this->fail('Should refuse: '.substr($bad, 0, 12));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $pdf = app(ProofStorage::class)->store(UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF"));
        $this->assertStringEndsWith('.pdf', $pdf);
    }

    public function test_proofs_are_served_as_inert_private_files(): void
    {
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 5]);
        $cart = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $cart])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'G', 'phone' => '0977000111', 'city' => 'Adama', 'address' => 'x'])->assertCreated();
        $order = Order::query()->firstOrFail();
        $this->postJson("/api/v1/orders/{$order->number}/payment-proof", ['phone' => '0977000111', 'method' => 'telebirr',
            'proof' => UploadedFile::fake()->createWithContent('r.jpg', $this->jpegWithHiddenData())])->assertCreated();
        $payment = Payment::query()->firstOrFail();

        $this->actingAsAdmin();
        $r = $this->get("/api/v1/admin/payments/{$payment->id}/proof")->assertOk();
        $r->assertHeader('Content-Type', 'image/jpeg');
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $r->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    // ── Secrets and logs ──────────────────────────────────────

    public function test_secrets_are_scrubbed_from_logs(): void
    {
        $line = RedactSecrets::clean('SMS failed bot 123456789:AAFabcdefghijklmnopqrstuvwxyz0123456 '
            .'Authorization: Bearer 1|abcdefghijklmnop "password":"hunter2-long" card 4111 1111 1111 1111 app base64:'.str_repeat('A', 44));
        foreach (['AAFabcdefghij', '1|abcdefghijklmnop', 'hunter2-long', '4111 1111 1111 1111', str_repeat('A', 44)] as $secret) {
            $this->assertStringNotContainsString($secret, $line);
        }
        // And it's wired into the log channels.
        $this->assertContains(RedactSecrets::class, config('logging.channels.single.tap'));
        $this->assertContains(RedactSecrets::class, config('logging.channels.daily.tap'));
    }

    public function test_no_secrets_are_kept_in_editable_settings(): void
    {
        foreach (array_filter(array_keys(config('settings')), fn ($k) => ! str_starts_with($k, 'notify.')) as $key) {
            $this->assertDoesNotMatchRegularExpression('/secret|token|api_key|password|private_key/i', $key, "Setting [$key] looks like a secret: keep it in .env");
        }
    }

    // ── Owner alerts and log retention ────────────────────────

    public function test_critical_events_alert_the_owner(): void
    {
        app(Ledger::class)->post('manual.adjustment', [[Account::ADJUSTMENTS, 100], [Account::CASH, -100]], reason: 'Bank fee correction', actorId: $this->admin->id);
        app(FraudGuard::class)->flag($this->member(), 'manual');
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/webhooks/telegram', ['update_id' => $i], ['X-Telegram-Bot-Api-Secret-Token' => 'forged'])->assertForbidden();
        }
        $titles = DB::table('activity_log')->where('action', 'security.critical')->pluck('properties')->map(fn ($p) => json_decode((string) $p, true)['title'])->all();
        $this->assertCount(3, $titles, implode(' | ', $titles));
        $this->assertStringContainsString('Manual ledger entry', $titles[0]);
        $this->assertStringContainsString('Fraud check', $titles[1]);
        $this->assertStringContainsString('wrong signature', $titles[2]);
    }

    public function test_telegram_retries_are_handled_once(): void
    {
        config(['services.telegram.webhook_secret' => 'tg-secret']);
        $update = ['update_id' => 777, 'message' => ['text' => 'hi', 'chat' => ['id' => 1], 'from' => ['id' => 1]]];
        $this->postJson('/api/v1/webhooks/telegram', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'tg-secret'])->assertOk()->assertJsonMissing(['duplicate' => true]);
        $this->postJson('/api/v1/webhooks/telegram', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'tg-secret'])->assertOk()->assertJson(['duplicate' => true]);
        $this->postJson('/api/v1/webhooks/telegram', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
    }

    public function test_routine_security_events_are_kept_13_months_and_audit_records_forever(): void
    {
        $old = now()->subMonths(14);
        DB::table('activity_log')->insert([
            ['action' => 'security.login_failed', 'created_at' => $old],
            ['action' => 'ledger.manual_entry', 'created_at' => $old],
            ['action' => 'security.login_failed', 'created_at' => now()->subMonths(6)],
        ]);
        $this->artisan('km:prune-logs')->assertSuccessful();
        $this->assertSame(1, DB::table('activity_log')->where('action', 'security.login_failed')->count());
        $this->assertSame(1, DB::table('activity_log')->where('action', 'ledger.manual_entry')->count());
        $this->assertContains('security.login_failed', PruneLogs::ROUTINE);
    }
}
