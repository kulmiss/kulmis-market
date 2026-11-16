<?php

namespace Tests\Feature\Shop;

use App\Events\OrderPlaced;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ShippingZone::query()->create(['name' => 'Everywhere', 'cities' => [], 'base_fee' => 0, 'is_default' => true]);
    }

    private function addToCart(Product $p, int $qty = 1, ?string $token = null, ?int $variant = null): string
    {
        $res = $this->withHeaders($token ? ['X-Cart-Token' => $token] : [])
            ->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'quantity' => $qty, 'variant_id' => $variant])->assertCreated();

        return (string) $res->json('token');
    }

    /** @return array<string, mixed> */
    private function customer(array $over = []): array
    {
        return array_merge(['checkout_key' => (string) Str::uuid(), 'first_name' => 'Hanna', 'last_name' => 'Tesfaye',
            'phone' => '0911 223 344', 'city' => 'Addis Ababa', 'address' => 'Bole, near Edna Mall'], $over);
    }

    public function test_guest_checkout_creates_order_and_decrements_stock(): void
    {
        Event::fake([OrderPlaced::class]);
        $p = Product::factory()->create(['price' => 150_000, 'stock' => 5]);
        $token = $this->addToCart($p, 2);

        $res = $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', $this->customer())->assertCreated();
        $res->assertJsonPath('data.total', 300_000)->assertJsonPath('data.status', 'pending_payment');

        $order = Order::query()->firstOrFail();
        $this->assertSame('+251911223344', $order->phone);
        $this->assertFalse($order->is_member_order);
        $this->assertSame(3, $p->fresh()->stock);
        $this->assertSame(0, Cart::query()->firstOrFail()->items()->count());
        $this->assertSame(1, $order->history()->count());
        $this->assertNotNull($order->device_hash);
        Event::assertDispatched(OrderPlaced::class);
    }

    public function test_member_checkout_gets_member_prices(): void
    {
        $member = User::factory()->member()->create();
        $p = Product::factory()->create(['price' => 200_000]);
        $this->actingAs($member, 'sanctum');
        $token = $this->addToCart($p);

        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', $this->customer())
            ->assertCreated()->assertJsonPath('data.total', 170_000)->assertJsonPath('data.member_discount', 30_000);
        $this->assertTrue(Order::query()->firstOrFail()->is_member_order);
        $this->assertSame($member->id, Order::query()->firstOrFail()->user_id);
    }

    public function test_same_checkout_key_never_creates_two_orders(): void
    {
        $p = Product::factory()->create(['stock' => 5]);
        $token = $this->addToCart($p);
        $payload = $this->customer();

        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', $payload)->assertCreated();
        $this->addToCart($p, 1, $token);
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', $payload)->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(4, $p->fresh()->stock);
    }

    public function test_cannot_oversell(): void
    {
        $p = Product::factory()->create(['stock' => 1]);
        $a = $this->addToCart($p);
        $b = $this->addToCart($p);

        $this->withHeaders(['X-Cart-Token' => $a])->postJson('/api/v1/checkout', $this->customer())->assertCreated();
        $this->withHeaders(['X-Cart-Token' => $b])->postJson('/api/v1/checkout', $this->customer())
            ->assertUnprocessable()->assertJsonPath('message', '“'.$p->name.'” is sold out.');
        $this->assertSame(0, $p->fresh()->stock);
    }

    public function test_guest_checkout_switch(): void
    {
        $token = $this->addToCart(Product::factory()->create());
        Features::set('shop.guest_checkout', false);

        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', $this->customer())
            ->assertForbidden()->assertJson(['login_required' => true]);
    }

    public function test_validation_of_phone_and_required_fields(): void
    {
        $token = $this->addToCart(Product::factory()->create());
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', $this->customer(['phone' => '12345', 'address' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['phone', 'address']);
    }

    public function test_coupon_is_applied_counted_and_per_customer_limited(): void
    {
        $coupon = Coupon::query()->create(['code' => 'ENKUTATASH', 'type' => 'fixed', 'value' => 20_000, 'max_uses_per_customer' => 1]);
        $p = Product::factory()->create(['price' => 100_000, 'cost_materials' => 20_000, 'cost_labour' => 0, 'cost_packaging' => 0]);

        $token = $this->addToCart($p);
        $this->withHeaders(['X-Cart-Token' => $token])->putJson('/api/v1/cart/coupon', ['code' => 'enkutatash'])
            ->assertOk()->assertJsonPath('quote.coupon_discount', 20_000);
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', $this->customer())->assertJsonPath('data.total', 80_000);
        $this->assertSame(1, $coupon->fresh()->used_count);

        $token2 = $this->addToCart($p);
        $this->withHeaders(['X-Cart-Token' => $token2])->putJson('/api/v1/cart/coupon', ['code' => 'ENKUTATASH'])->assertOk();
        // Same phone → refused at checkout.
        $this->withHeaders(['X-Cart-Token' => $token2])->postJson('/api/v1/checkout', $this->customer())
            ->assertUnprocessable()->assertJsonPath('message', 'You have already used this coupon.');
    }

    public function test_invalid_coupon_is_not_saved_on_cart(): void
    {
        $token = $this->addToCart(Product::factory()->create());
        $this->withHeaders(['X-Cart-Token' => $token])->putJson('/api/v1/cart/coupon', ['code' => 'NOPE'])->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_contains(strtolower($m), 'coupon'));
        $this->assertNull(Cart::query()->firstOrFail()->coupon_code);
    }

    public function test_cart_and_checkout_show_problems_on_the_page_not_in_a_pop_up(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));
        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])alert\(/', $js, 'Use sayError(): a browser pop-up is hard to read on a phone.');
        $this->assertStringContainsString("setAttribute('role', 'alert')", $js);
    }

    public function test_cart_shows_join_and_save_for_guests(): void
    {
        $token = $this->addToCart(Product::factory()->create(['price' => 200_000]));
        $this->withHeaders(['X-Cart-Token' => $token])->getJson('/api/v1/cart')
            ->assertOk()->assertJsonPath('quote.member_savings_preview', 30_000)->assertJsonPath('quote.lines.0.member_unit_price', 170_000);
    }

    public function test_cart_quantity_update_and_remove(): void
    {
        $token = $this->addToCart(Product::factory()->create(['price' => 10_000]), 2);
        $id = $this->withHeaders(['X-Cart-Token' => $token])->getJson('/api/v1/cart')->json('items.0.id');

        $this->withHeaders(['X-Cart-Token' => $token])->patchJson("/api/v1/cart/items/$id", ['quantity' => 5])->assertJsonPath('quote.total', 50_000);
        $this->withHeaders(['X-Cart-Token' => $token])->patchJson("/api/v1/cart/items/$id", ['quantity' => 0])->assertJsonCount(0, 'items');
    }

    public function test_cart_cookie_is_essential_and_holds_only_a_token(): void
    {
        $res = $this->postJson('/api/v1/cart/items', ['product_id' => Product::factory()->create()->id])->assertCreated();
        $cookie = collect($res->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'km_cart');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_tracking_needs_number_and_matching_phone(): void
    {
        $token = $this->addToCart(Product::factory()->create());
        $number = $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', $this->customer())->json('data.number');

        $this->postJson('/api/v1/orders/track', ['number' => $number, 'phone' => '0900000000'])->assertNotFound();
        $this->postJson('/api/v1/orders/track', ['number' => strtolower($number), 'phone' => '+251 911 223 344'])
            ->assertOk()->assertJsonPath('data.status', 'pending_payment')->assertJsonCount(1, 'data.timeline');
    }

    public function test_unpaid_orders_are_cancelled_and_restocked(): void
    {
        $coupon = Coupon::query()->create(['code' => 'X', 'type' => 'fixed', 'value' => 100]);
        $p = Product::factory()->create(['stock' => 3]);
        $token = $this->addToCart($p, 2);
        $this->withHeaders(['X-Cart-Token' => $token])->putJson('/api/v1/cart/coupon', ['code' => 'X']);
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', $this->customer())->assertCreated();
        $this->assertSame(1, $p->fresh()->stock);

        $this->travel(49)->hours();
        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $order = Order::query()->firstOrFail();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame(3, $p->fresh()->stock);
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_made_to_order_items_keep_making_days(): void
    {
        $p = Product::factory()->madeToOrder(12)->create();
        $token = $this->addToCart($p, 3);
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', $this->customer())->assertCreated();
        $item = Order::query()->firstOrFail()->items()->firstOrFail();
        $this->assertTrue($item->made_to_order);
        $this->assertSame(12, $item->making_days);
        $this->assertNull($p->fresh()->stock);
    }

    public function test_order_snapshot_keeps_cost_for_profit_reports(): void
    {
        $p = Product::factory()->create(['price' => 200_000]);
        $token = $this->addToCart($p);
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', $this->customer())->assertCreated();
        $this->assertSame(75_000, Order::query()->firstOrFail()->items()->firstOrFail()->unit_cost);
    }
}
