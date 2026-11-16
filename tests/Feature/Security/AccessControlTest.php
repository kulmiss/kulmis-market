<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ThrottlePerRoute;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutRequest;
use App\Models\Product;
use App\Models\User;
use App\Payments\PaymentService;
use App\Support\Totp;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Feature\Referral\ReferralTestCase;

/**
 * Every route against every role (guest, member, expired member, each
 * staff role, Super Admin), plus "can I see someone else's …?" (IDOR).
 */
class AccessControlTest extends ReferralTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottlePerRoute::class, ValidateCsrfToken::class]);
    }

    /** @return list<Route> */
    private function routes(callable $filter): array
    {
        return array_values(array_filter(app('router')->getRoutes()->getRoutes(), $filter));
    }

    private function uri(Route $r): string
    {
        return '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $r->uri()), '/');
    }

    private function method(Route $r): string
    {
        return collect($r->methods())->first(fn ($m) => $m !== 'HEAD') ?? 'GET';
    }

    /** @param list<string> $mw */
    private static function has(Route $r, string $needle): bool
    {
        return collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, $needle));
    }

    public function test_guests_never_reach_a_signed_in_route(): void
    {
        $routes = $this->routes(fn (Route $r) => self::has($r, 'auth') && ! str_starts_with($r->uri(), 'admin/'));
        $this->assertGreaterThan(40, count($routes));
        foreach ($routes as $r) {
            $res = $this->json($this->method($r), $this->uri($r));
            $this->assertContains($res->status(), [401, 302, 404], $this->method($r).' '.$r->uri().' answered '.$res->status().' to a guest');
            if ($res->status() === 302) {
                $this->assertStringEndsWith('/login', (string) $res->headers->get('Location'), $r->uri());
            }
        }
    }

    public function test_customers_members_and_expired_members_never_reach_the_admin_api(): void
    {
        $member = $this->member();
        $expired = $this->member(null, 'Old');
        Membership::query()->where('user_id', $expired->id)->update(['status' => 'expired', 'current_period_end' => now()->subMonth(), 'grace_ends_at' => now()->subWeeks(3)]);
        $customer = User::factory()->create();
        $routes = $this->routes(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/admin/') && ! in_array($r->uri(), ['api/v1/admin/auth/login', 'api/v1/admin/auth/two-factor'], true));
        $this->assertGreaterThan(70, count($routes));

        foreach ([$customer, $member, $expired] as $who) {
            foreach (['web', 'sanctum'] as $guard) {
                $this->app['auth']->forgetGuards();
                $this->actingAs($who, $guard);
                foreach ($routes as $r) {
                    $status = $this->json($this->method($r), $this->uri($r))->getStatusCode();
                    $this->assertContains($status, [401, 403, 404], "{$who->first_name} ($guard) got $status on ".$this->method($r).' '.$r->uri());
                }
            }
        }
    }

    public function test_each_staff_role_only_reaches_its_abilities(): void
    {
        $routes = $this->routes(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/admin/') && self::has($r, 'can:'));
        foreach (array_keys(config('permissions.roles')) as $role) {
            $this->app['auth']->forgetGuards();
            $admin = $this->actingAsAdmin($role);
            foreach ($routes as $r) {
                // Every can: rule on the route (group + route) must pass, through the Gate so combined abilities like view-payments count.
                $abilities = collect($r->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'can:'))->map(fn ($m) => substr($m, 4));
                $ability = $abilities->implode(' + ');
                $allowed = $abilities->every(fn ($a) => $admin->can($a));
                $status = $this->json($this->method($r), $this->uri($r))->getStatusCode();
                if ($allowed) {
                    $this->assertNotSame(403, $status, "$role should reach ".$this->method($r).' '.$r->uri());
                } else {
                    // 404 = the record id in the URL doesn't exist (checked before the ability); never a 2xx.
                    $this->assertContains($status, [403, 404], "$role must NOT reach ".$this->method($r).' '.$r->uri()." ($ability), got $status");
                }
            }
        }
    }

    public function test_staff_need_a_2fa_browser_session_not_a_token(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $token = $admin->createToken('x')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/features')->assertStatus(401);
        $this->flushHeaders();

        // Signed in but 2FA not set up: only the setup screens work.
        $this->actingAs($admin, 'web');
        $this->getJson('/api/v1/admin/features')->assertForbidden()->assertJson(['two_factor_setup_required' => true]);
        $this->postJson('/api/v1/admin/two-factor/setup')->assertOk();

        // Bearer token on top of a 2FA session is still refused.
        $admin->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->withToken($token)->getJson('/api/v1/admin/features')->assertForbidden();
    }

    public function test_staff_cannot_get_customer_app_tokens(): void
    {
        User::factory()->role('finance')->create(['email' => 'fin@example.com', 'password' => 'finance pass phrase']);
        $this->postJson('/api/v1/auth/login', ['login' => 'fin@example.com', 'password' => 'finance pass phrase'])->assertUnprocessable();
    }

    public function test_nobody_sees_another_customers_orders(): void
    {
        $alice = User::factory()->create(['phone' => '+251911000001']);
        $bob = User::factory()->create(['phone' => '+251911000002']);
        $order = Order::query()->create(['number' => 'KM261116-00001', 'user_id' => $alice->id, 'first_name' => 'Alice', 'phone' => '+251911000001',
            'city' => 'A', 'address' => 'B', 'list_subtotal' => 100, 'merchandise_total' => 100, 'total' => 100, 'checkout_key' => 'k1']);

        $this->actingAs($bob, 'sanctum')->getJson('/api/v1/me/orders/'.$order->number)->assertNotFound();
        $this->assertSame([], $this->actingAs($bob, 'sanctum')->getJson('/api/v1/me/orders')->json('data'));
        $this->app['auth']->forgetGuards();
        $this->actingAs($bob, 'web')->get('/pay/'.$order->number)->assertNotFound();
        $this->app['auth']->forgetGuards();
        // Tracking and payment need the matching phone, not just the number.
        $this->postJson('/api/v1/orders/track', ['number' => $order->number, 'phone' => '0911000002'])->assertNotFound();
        $this->postJson('/api/v1/orders/'.$order->number.'/payment-options', ['phone' => '0911000002'])->assertNotFound();
        $this->postJson('/api/v1/orders/'.$order->number.'/payment-options', ['phone' => '0911000001'])->assertOk();
    }

    public function test_receipts_need_a_valid_signed_link(): void
    {
        $order = $this->buyProducts(100_000);
        $payment = Payment::query()->where('payable_id', $order->id)->firstOrFail();
        $this->get('/api/v1/payments/'.$payment->uuid.'/receipt')->assertForbidden();
        $signed = URL::signedRoute('api.payments.receipt', ['payment' => $payment->uuid]);
        $this->get($signed.'x')->assertForbidden();
        $this->get(str_replace($payment->uuid, (string) Str::uuid(), $signed))->assertForbidden();
        $this->get($signed)->assertOk();
    }

    public function test_wallet_team_and_cashouts_only_ever_show_your_own(): void
    {
        [, , $l1] = $this->chain();
        $other = $this->member(null, 'Zed');
        app(Ledger::class)->post('manual.adjustment', [[app(Ledger::class)->userAccount($other, Wallet::AVAILABLE), -777_700], [Account::ADJUSTMENTS, 777_700]], reason: 't');
        PayoutRequest::query()->create(['user_id' => $other->id, 'amount' => 100_000, 'method' => 'telebirr', 'account_name' => 'Zed X',
            'account_number' => '0911999999', 'account_fingerprint' => 'f', 'status' => 'requested', 'due_at' => now()]);

        $w = $this->actingAs($l1, 'sanctum')->getJson('/api/v1/wallet')->assertOk()->json('data');
        $this->assertSame(0, $w['balances']['available']);
        $this->assertSame([], $w['cashouts']);
        $team = $this->getJson('/api/v1/referral')->assertOk()->getContent();
        $this->assertStringNotContainsString('0911999999', $team);
        $this->assertStringNotContainsString('Zed', $team);
        // Query-string tricks don't switch the user.
        $this->getJson('/api/v1/wallet?user_id='.$other->id)->assertJsonPath('data.balances.available', 0);
        $this->getJson('/api/v1/referral?user='.$other->id)->assertJsonMissing(['code' => $other->referral_code]);
    }

    public function test_payment_proofs_are_private_files(): void
    {
        $order = $this->buyProducts(100_000);
        $payment = Payment::query()->where('payable_id', $order->id)->firstOrFail();
        $this->assertNotNull($payment->proof_path);
        $this->get('/storage/'.$payment->proof_path)->assertNotFound();
        $this->getJson('/api/v1/admin/payments/'.$payment->id.'/proof')->assertUnauthorized();
        $this->actingAsAdmin('fulfilment');
        $this->get('/api/v1/admin/payments/'.$payment->id.'/proof')->assertOk();
    }

    public function test_only_finance_or_managers_approve_transfers(): void
    {
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 5]);
        $cart = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $cart])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'G', 'phone' => '0977000111', 'city' => 'Adama', 'address' => 'x'])->assertCreated();
        $order = Order::query()->latest('id')->firstOrFail();
        $proof = app(PaymentService::class)->submitProof($order, Payment::BANK, UploadedFile::fake()->image('p.jpg'), 'R2', null);
        foreach (['fulfilment', 'support'] as $role) {
            $this->app['auth']->forgetGuards();
            $this->actingAsAdmin($role);
            $this->postJson("/api/v1/admin/payments/{$proof->id}/approve")->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->actingAsAdmin('finance');
        $this->postJson("/api/v1/admin/payments/{$proof->id}/approve")->assertOk();
    }

    public function test_only_super_admin_changes_a_referrer_even_if_a_role_gets_the_ability(): void
    {
        config(['permissions.roles.manager.abilities' => array_merge(config('permissions.roles.manager.abilities'), ['manage-referrals'])]);
        $u = $this->member();
        $this->actingAsAdmin('manager');
        $this->postJson("/api/v1/admin/referrals/members/{$u->id}/referrer", ['referrer_id' => null, 'reason' => 'testing'])->assertForbidden();
    }

    public function test_every_staff_role_can_read_which_features_are_on(): void
    {
        foreach (['fulfilment', 'support', 'finance', 'manager'] as $role) {
            $this->app['auth']->forgetGuards();
            $this->actingAsAdmin($role);
            $this->getJson('/api/v1/admin/features/state')->assertOk()->assertJsonFragment(['key' => 'shop', 'effective' => true])
                ->assertJsonMissingPath('data.0.description')->assertJsonMissingPath('data.0.enabled')->assertJsonMissingPath('data.0.parent');
            $this->getJson('/api/v1/admin/features')->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/v1/admin/features/state')->assertForbidden(); // customers: no
    }
}
