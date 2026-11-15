<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ThrottlePerRoute;
use App\Models\Badge;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Order;
use App\Models\Page;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Tests\Feature\Referral\ReferralTestCase;

/** Automated attacks: stored XSS, odd query strings, mass assignment, open redirects, enumeration. */
class AttackTest extends ReferralTestCase
{
    private const XSS = '<script>alert("x")</script><img src=x onerror=alert(1)>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottlePerRoute::class, ValidateCsrfToken::class]);
        ShippingZone::query()->create(['name' => 'All', 'cities' => [], 'base_fee' => 10_000, 'is_default' => true]);
    }

    private function assertSafe(string $html, string $where): void
    {
        $this->assertStringNotContainsString('<script>alert', $html, "raw script in $where");
        $this->assertStringNotContainsString('<img src=x onerror', $html, "raw img onerror in $where");
    }

    public function test_stored_xss_payloads_are_escaped_on_every_page(): void
    {
        $cat = Category::query()->create(['name' => 'Cat '.self::XSS, 'slug' => 'cat-x', 'is_active' => true]);
        Product::factory()->create(['name' => 'Basket '.self::XSS, 'slug' => 'basket-x', 'description' => "Nice\n\n".self::XSS,
            'category_id' => $cat->id, 'price' => 100_000, 'stock' => 5, 'is_featured' => true]);
        Page::query()->updateOrCreate(['slug' => 'terms'], ['title' => 'Terms '.self::XSS, 'body' => "# Hi\n\n".self::XSS."\n\n[link](javascript:alert(1))", 'is_published' => true]);
        Banner::query()->create(['placement' => 'top_bar', 'title' => 'Sale '.self::XSS, 'is_active' => true]);
        Settings::set('site.name', 'Kulmis '.self::XSS);
        Settings::set('payments.telebirr_details', 'Pay '.self::XSS);
        Badge::query()->where('key', 'early_supporter')->update(['name' => '<script>alert("x")</script>']); // badge names are short (40)

        foreach (['/', '/shop', '/shop/basket-x', '/c/cat-x', '/p/terms', '/membership', '/signals', '/track', '/login'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertSafe((string) $html, $path);
            $this->assertStringNotContainsString('href="javascript:', (string) $html, "javascript: link on $path");
        }

        // A customer with a hostile name, seen by the member area, pay page and admin JSON.
        $member = $this->member(null, 'Mal');
        $member->forceFill(['first_name' => self::XSS])->save();
        foreach (['/account', '/account/team', '/account/badges', '/account/wallet'] as $path) {
            $this->assertSafe((string) $this->actingAs($member)->get($path)->assertOk()->getContent(), $path);
        }
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => Product::query()->firstOrFail()->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => self::XSS, 'phone' => '0933445566', 'city' => self::XSS, 'address' => self::XSS, 'notes' => self::XSS])->assertCreated();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->app['auth']->forgetGuards();
        $this->assertSafe((string) $this->get("/pay/{$order->number}?phone=0933445566")->assertOk()->getContent(), 'pay page');
        $receipt = view('pdf.receipt', ['payment' => new Payment(['tx_ref' => 'KM-X', 'amount' => 1, 'provider' => 'bank_transfer', 'paid_at' => now()]),
            'payable' => $order, 'order' => $order->load('items'), 'customer' => $order->payableCustomer(), 'title' => $order->payableTitle()])->render();
        $this->assertSafe($receipt, 'PDF receipt');
    }

    public function test_array_and_huge_query_values_never_crash_a_page(): void
    {
        Product::factory()->count(3)->create();
        $this->actingAsAdmin();
        $routes = array_filter(app('router')->getRoutes()->getRoutes(), fn (Route $r) => in_array('GET', $r->methods(), true)
            && ! str_contains($r->uri(), '{') && ! str_contains($r->uri(), 'proof') && ! str_contains($r->uri(), 'export'));
        $evil = '?q[]=x&sort[]=x&status[]=x&phone[]=1&type[]=x&month[]=1&from[]=1&page[]=2&per_page=99999999&category[]=x';
        foreach ($routes as $r) {
            $status = $this->get('/'.ltrim($r->uri(), '/').$evil, ['Accept' => str_starts_with($r->uri(), 'api/') ? 'application/json' : 'text/html'])->status();
            $this->assertLessThan(500, $status, 'GET /'.$r->uri()." crashed ($status)");
        }
        $this->getJson('/api/v1/admin/members?per_page=99999999')->assertOk()->assertJsonPath('per_page', 200);
        $this->get('/pay/KM1?phone[]=1')->assertNotFound();
        $this->get('/shop?sort[]=x')->assertOk();
    }

    public function test_mass_assignment_cannot_set_roles_or_money_fields(): void
    {
        $this->post('/register', ['first_name' => 'Eve', 'last_name' => 'Hacker', 'terms' => '1', 'phone' => '0911555666', 'email' => 'eve@example.com',
            'password' => 'purple otter swims daily 9', 'password_confirmation' => 'purple otter swims daily 9',
            'role' => 'super_admin', 'is_demo' => true, 'referred_by' => 1, 'two_factor_confirmed_at' => now(), 'id' => 1])->assertRedirect('/account?welcome=1');
        $eve = User::query()->where('email', 'eve@example.com')->firstOrFail();
        $this->assertSame('customer', $eve->role);
        $this->assertFalse((bool) $eve->is_demo);
        $this->assertNull($eve->referred_by);
        $this->assertNull($eve->two_factor_confirmed_at);

        $this->actingAs($eve, 'sanctum')->patchJson('/api/v1/account', ['first_name' => 'Eve2', 'role' => 'super_admin', 'phone_verified_at' => now()]);
        $this->assertSame('customer', $eve->fresh()->role);
    }

    public function test_sign_in_and_reset_answers_do_not_reveal_who_has_an_account(): void
    {
        User::factory()->create(['email' => 'known@example.com', 'phone' => '+251911000111']);
        $known = $this->postJson('/api/v1/auth/login', ['login' => 'known@example.com', 'password' => 'wrong wrong wrong 1']);
        $unknown = $this->postJson('/api/v1/auth/login', ['login' => 'nobody@example.com', 'password' => 'wrong wrong wrong 1']);
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));

        $a = $this->postJson('/api/v1/auth/password/forgot', ['login' => 'known@example.com']);
        $b = $this->postJson('/api/v1/auth/password/forgot', ['login' => 'nobody@example.com']);
        $this->assertSame($a->status(), $b->status());
        $this->assertSame($a->json('message'), $b->json('message'));
    }

    public function test_no_open_redirects(): void
    {
        foreach (['https://evil.example', '//evil.example', '/\\evil.example'] as $to) {
            $res = $this->get('/r/ABC12?next='.urlencode($to));
            $this->assertStringStartsWith(rtrim((string) config('app.url'), '/'), (string) $res->headers->get('Location'));
            $this->assertStringNotContainsString('evil.example', (string) $res->headers->get('Location'));
            $login = $this->withSession(['url.intended' => $to])->post('/login', ['login' => 'x@example.com', 'password' => 'nope nope nope 1']);
            $this->assertStringNotContainsString('evil.example', (string) $login->headers->get('Location'));
        }
    }

    public function test_last_item_in_stock_is_sold_once(): void
    {
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 1]);
        $place = function (string $phone) use ($p) {
            $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');

            return $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
                'first_name' => 'A', 'phone' => $phone, 'city' => 'Adama', 'address' => 'x']);
        };
        $first = $place('0911000201');
        $this->flushHeaders();
        $second = $place('0911000202');
        $this->assertSame(201, $first->status());
        $this->assertSame(422, $second->status());
        $this->assertSame(0, $p->fresh()->stock);
        $this->assertSame(1, Order::query()->count());
    }
}
