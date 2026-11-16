<?php

namespace Tests\Feature\Storefront;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use App\Referral\ReferralCodes;
use App\Storefront\PageCache;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PageCache::clear();
        parent::tearDown();
    }

    public function test_home_and_shop_render_with_member_prices(): void
    {
        $cat = Category::factory()->create(['name' => 'Baskets', 'slug' => 'baskets']);
        Product::factory()->create(['name' => 'Mesob basket', 'price' => 200_000, 'is_featured' => true, 'category_id' => $cat->id]);

        $this->get('/')->assertOk()->assertSee('Mesob basket')->assertSee('1,700 ETB')->assertSee('member price');
        $this->get('/shop')->assertOk()->assertSee('Mesob basket');
        $this->get('/c/baskets')->assertOk()->assertSee('Baskets');
    }

    public function test_guest_pages_set_no_cookies_and_store_no_session(): void
    {
        Product::factory()->create();
        $res = $this->get('/');
        $res->assertOk();
        $this->assertSame([], $res->headers->getCookies(), 'no session or tracking cookie for guests');
        $this->assertDatabaseCount('sessions', 0);
        $res->assertHeader('Cache-Control', 'max-age=0, must-revalidate, public'); // never a stale guest page after signing in
    }

    public function test_page_cache_writes_static_html_for_guests_only(): void
    {
        config(['shop.page_cache_in_tests' => true]);
        Product::factory()->create(['slug' => 'clay-pot', 'name' => 'Clay pot']);

        $this->get('/shop/clay-pot')->assertOk()->assertHeader('X-Page-Cache', 'stored');
        $this->assertFileExists(public_path('page-cache/shop/clay-pot.html'));
        $this->assertStringContainsString('Clay pot', File::get(public_path('page-cache/shop/clay-pot.html')));

        // Query strings (e.g. ?ref=) and signed-in users are never cached.
        $this->get('/shop?sort=price_asc')->assertOk()->assertHeaderMissing('X-Page-Cache');
        $this->actingAs(User::factory()->create())->withCookie(config('session.cookie'), 'x')->get('/shop')->assertHeaderMissing('X-Page-Cache');

        // Any catalog change clears the cache.
        Product::query()->firstOrFail()->update(['name' => 'Clay pot v2']);
        $this->assertFileDoesNotExist(public_path('page-cache/shop/clay-pot.html'));
    }

    public function test_product_page_has_seo_jsonld_and_members_only_cta(): void
    {
        Product::factory()->create(['slug' => 'gabi', 'name' => 'Gabi', 'is_members_only' => true, 'seo_description' => 'Soft cotton gabi']);
        $this->get('/shop/gabi')->assertOk()
            ->assertSee('"@type":"Product"', false)->assertSee('<meta name="description" content="Soft cotton gabi">', false)
            ->assertSee('og:image', false)->assertSee('Join to buy this piece');
    }

    public function test_members_see_early_access_on_live_render(): void
    {
        $member = User::factory()->member()->create();
        Product::factory()->create(['slug' => 'soon', 'name' => 'Soon basket', 'release_at' => now()->addHours(20)]);

        $this->get('/shop/soon')->assertNotFound();
        $this->actingAs($member)->get('/shop/soon')->assertOk()->assertSee('Members early access');
    }

    public function test_landing_pages_follow_switches(): void
    {
        $this->get('/membership')->assertOk()->assertSee('5,000 ETB')->assertSee('15% off everything');
        $this->get('/signals')->assertOk()->assertSee('4,000 ETB')->assertSee('Trading involves risk');
        $this->get('/referral-program')->assertOk()->assertSee('10%')->assertSee('Level 3');
        Features::set('signals', false);
        $this->get('/signals')->assertNotFound();
        $this->get('/')->assertDontSee('href="'.url('/signals').'"', false);
    }

    public function test_pages_banners_sitemap_robots(): void
    {
        Page::query()->create(['slug' => 'terms', 'title' => 'Terms of sale', 'body' => "## Hello\n\n<script>x</script>"]);
        Banner::query()->create(['placement' => 'top_bar', 'title' => 'Timket sale this week']);
        Product::factory()->create(['slug' => 'cup']);

        $this->get('/p/terms')->assertOk()->assertSee('<h2>Hello</h2>', false)->assertDontSee('<script>x</script>', false);
        $this->get('/')->assertSee('Timket sale this week')->assertSee('Terms of sale');
        $this->get('/sitemap.xml')->assertOk()->assertSee(url('/shop/cup'), false)->assertSee(url('/p/terms'), false);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin');
    }

    public function test_register_login_and_account_dashboard(): void
    {
        $ref = User::factory()->member()->create(['first_name' => 'Abdi']);
        app(ReferralCodes::class)->assign($ref);

        $this->get('/register')->assertOk();
        $this->post('/register', ['first_name' => 'Meron', 'last_name' => 'Alemu', 'phone' => '0911456789', 'password' => 'buna-time-2026x',
            'password_confirmation' => 'buna-time-2026x', 'referral_code' => $ref->fresh()->referral_code, 'terms' => '1'])->assertRedirect('/account?welcome=1');
        $user = User::query()->where('phone', '+251911456789')->firstOrFail();
        $this->assertSame($ref->id, $user->referrer_id);

        $this->get('/account')->assertOk()->assertSeeInOrder(['Selam,', 'Meron'])->assertSee('Your referral link')->assertSee($user->referral_code);
        $this->get('/account/referral-qr.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

        // Sign out: the member area asks first, then the home page says so.
        $this->get('/account')->assertSee('data-confirm="Do you want to sign out?" data-confirm-ok="Sign out"', false)->assertSee('data-ask', false);
        $this->post('/logout')->assertRedirect('/?signed_out=1');
        $this->assertGuest();
        $this->get('/?signed_out=1')->assertOk()->assertSee('You are signed out.')->assertSee('data-ask', false);
        $this->assertFileDoesNotExist(public_path('page-cache/index.html'), 'the message page is never cached for others');
        $this->get('/')->assertOk()->assertDontSee('You are signed out.');
        $this->post('/login', ['login' => '0911456789', 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => '0911456789', 'password' => 'buna-time-2026x'])->assertRedirect('/account');
        $this->get('/?signed_out=1')->assertDontSee('You are signed out.'); // signed in again: no message
    }

    public function test_sign_up_with_a_used_phone_or_email_says_sign_in_instead(): void
    {
        User::factory()->create(['phone' => '+251911456789', 'email' => 'meron@example.com']);
        $form = ['first_name' => 'Meron', 'last_name' => 'Alemu', 'phone' => '0911456789', 'email' => 'Meron@Example.com',
            'password' => 'buna-time-2026x', 'password_confirmation' => 'buna-time-2026x', 'terms' => '1'];

        $this->from('/register')->post('/register', $form)->assertRedirect('/register')->assertSessionHasErrors([
            'phone' => 'This phone number already has an account. Please sign in instead.',
            'email' => 'This email already has an account. Please sign in instead.',
        ]);
        $this->postJson('/api/v1/auth/register', $form)->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'This phone number already has an account. Please sign in instead.');
    }

    public function test_account_requires_login(): void
    {
        $this->get('/account')->assertRedirect('/login');
    }

    public function test_member_checkout_on_the_website(): void
    {
        ShippingZone::query()->create(['name' => 'All', 'cities' => [], 'base_fee' => 10_000, 'is_default' => true]);
        $member = User::factory()->member()->create();
        $p = Product::factory()->create(['price' => 200_000]);
        $token = $this->actingAs($member, 'sanctum')->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');

        $this->actingAs($member)->withCookie('km_cart', $token)->withUnencryptedCookie('km_cart', $token)
            ->withHeader('X-Cart-Token', $token)
            ->post('/checkout', ['checkout_key' => (string) Str::uuid(), 'first_name' => 'Hana', 'phone' => '0911000999', 'city' => 'Adama', 'address' => 'Main road'])
            ->assertRedirect();
        $order = Order::query()->firstOrFail();
        $this->assertSame(170_000 + 10_000, $order->total);
        $this->get('/pay/'.$order->number)->assertOk()->assertSee('Pay for order');
    }

    public function test_pay_page_needs_owner_or_phone(): void
    {
        $o = Order::query()->create(['number' => 'KM261115-00001', 'first_name' => 'X', 'phone' => '+251911000000', 'city' => 'A', 'address' => 'B',
            'list_subtotal' => 100, 'merchandise_total' => 100, 'total' => 100, 'checkout_key' => 'k1']);
        $this->get('/pay/'.$o->number)->assertNotFound();
        $this->get('/pay/'.$o->number.'?phone=0911000000')->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    }
}
