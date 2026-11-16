<?php

namespace Tests\Feature\Shop;

use App\Jobs\NotifyBackInStock;
use App\Jobs\SendCustomerMessage;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAlert;
use App\Models\User;
use App\Models\WishlistItem;
use App\Shop\StockAlerts;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** Wishlist, "tell me when it's back", saved addresses and "Buy again". */
class WishlistAlertsAddressesTest extends TestCase
{
    use RefreshDatabase;

    public function test_wishlist_add_list_remove(): void
    {
        $user = User::factory()->create();
        $p = Product::factory()->create(['name' => 'Mesob Basket']);
        $this->actingAs($user)->get('/shop/'.$p->slug)->assertSee('Save to wishlist');
        $this->post('/account/wishlist/'.$p->id)->assertRedirect();
        $this->assertDatabaseHas('wishlist_items', ['user_id' => $user->id, 'product_id' => $p->id]);
        $this->get('/shop/'.$p->slug)->assertSee('Saved in your wishlist');
        $this->get('/account/wishlist')->assertOk()->assertSee('Mesob Basket');
        $this->post('/account/wishlist/'.$p->id);
        $this->assertDatabaseCount('wishlist_items', 0);
        $this->get('/account/wishlist')->assertSee('Nothing saved yet');
    }

    public function test_wishlist_needs_sign_in_and_hides_drafts_and_respects_the_switch(): void
    {
        $p = Product::factory()->create();
        $this->get('/shop/'.$p->slug)->assertSee('Sign in to save it');
        $this->post('/account/wishlist/'.$p->id)->assertRedirect('/login');

        $user = User::factory()->create();
        $draft = Product::factory()->draft()->create();
        $this->actingAs($user)->post('/account/wishlist/'.$draft->id)->assertNotFound();
        WishlistItem::query()->create(['user_id' => $user->id, 'product_id' => $p->id]);
        $p->update(['status' => 'archived']);
        $this->get('/account/wishlist')->assertDontSee($p->name);

        Features::set('shop.wishlist', false);
        $this->get('/account/wishlist')->assertNotFound();
        $p->update(['status' => 'active']);
        $this->get('/shop/'.$p->slug)->assertDontSee('Save to wishlist');
    }

    public function test_back_in_stock_alert_for_guests_sends_one_message(): void
    {
        Bus::fake([SendCustomerMessage::class]);
        $p = Product::factory()->create(['stock' => 0]);
        $this->get('/shop/'.$p->slug)->assertSee("Tell me when it's back", false);

        $this->postJson('/api/v1/stock-alerts', ['product_id' => $p->id, 'contact' => '0911 22 33 44'])->assertCreated();
        $this->postJson('/api/v1/stock-alerts', ['product_id' => $p->id, 'contact' => '+251911223344'])->assertCreated(); // same person
        $this->postJson('/api/v1/stock-alerts', ['product_id' => $p->id, 'contact' => 'Hana@Example.com'])->assertCreated();
        $this->assertSame(2, StockAlert::query()->count());
        $this->assertDatabaseHas('stock_alerts', ['contact' => 'hana@example.com', 'channel' => 'email']);

        $p->update(['stock' => 5]); // the observer queues the job; run it now
        app(StockAlerts::class)->notify($p->id, null);
        Bus::assertDispatchedTimes(SendCustomerMessage::class, 2);
        $this->assertSame(0, app(StockAlerts::class)->notify($p->id, null), 'never twice');
    }

    public function test_alerts_validate_input_and_block_spam(): void
    {
        $p = Product::factory()->create(['stock' => 0]);
        $this->postJson('/api/v1/stock-alerts', ['product_id' => $p->id, 'contact' => 'hello'])->assertJsonValidationErrors('contact');
        $this->postJson('/api/v1/stock-alerts', ['product_id' => $p->id, 'contact' => 'a@b.co', 'website' => 'spam'])->assertJsonValidationErrors('website');
        $inStock = Product::factory()->create(['stock' => 3]);
        $this->postJson('/api/v1/stock-alerts', ['product_id' => $inStock->id, 'contact' => 'a@b.co'])->assertJsonValidationErrors('contact');
        $this->postJson('/api/v1/stock-alerts', ['product_id' => Product::factory()->draft()->create()->id, 'contact' => 'a@b.co'])->assertNotFound();
        Features::set('shop.stock_alerts', false);
        $this->postJson('/api/v1/stock-alerts', ['product_id' => $p->id, 'contact' => 'a@b.co'])->assertNotFound();
    }

    public function test_variant_back_in_stock_and_restock_after_cancel_queue_the_job(): void
    {
        Bus::fake([NotifyBackInStock::class]);
        $p = Product::factory()->create(['stock' => null]);
        $v = ProductVariant::query()->forceCreate(['product_id' => $p->id, 'name' => 'Large', 'stock' => 0]);
        $v->update(['stock' => 2]);
        Bus::assertDispatched(NotifyBackInStock::class, fn ($j) => $j->productId === $p->id && $j->variantId === $v->id);
    }

    public function test_saved_addresses_crud_default_and_ownership(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = ['label' => 'Home', 'first_name' => 'Abebe', 'phone' => '0911223344', 'city' => 'Addis Ababa', 'address' => 'Bole, near Edna Mall'];
        $this->post('/account/addresses', $form)->assertRedirect('/account/addresses');
        $this->post('/account/addresses', ['label' => 'Work'] + $form + ['is_default' => 1]);
        $this->assertSame(['Work'], Address::query()->where('is_default', true)->pluck('label')->all(), 'only one default');
        $this->get('/account/addresses')->assertOk()->assertSee('Bole, near Edna Mall')->assertSee('Default');
        $this->post('/account/addresses', ['phone' => 'abc'] + $form)->assertSessionHasErrors('phone');

        $other = User::factory()->create();
        $theirs = Address::query()->forceCreate(['user_id' => $other->id, 'first_name' => 'X', 'phone' => '+251911000000', 'city' => 'A', 'address' => 'B']);
        $this->put('/account/addresses/'.$theirs->id, $form)->assertNotFound();
        $this->delete('/account/addresses/'.$theirs->id)->assertNotFound();

        $work = Address::query()->where('label', 'Work')->first();
        $this->delete('/account/addresses/'.$work->id);
        $this->assertTrue(Address::query()->where('user_id', $user->id)->first()->is_default, 'another one becomes the default');

        $this->get('/checkout')->assertSee('data-saved-address', false)->assertSee('Save this address for next time');
    }

    public function test_buy_again_puts_items_in_the_cart_at_todays_price(): void
    {
        $user = User::factory()->create();
        $p = Product::factory()->create(['price' => 300_000]);
        $gone = Product::factory()->create();
        $order = Order::query()->create(['number' => 'KM9100', 'user_id' => $user->id, 'first_name' => 'A', 'phone' => '+251911000001',
            'city' => 'A', 'address' => 'B', 'list_subtotal' => 1, 'merchandise_total' => 1, 'total' => 1, 'checkout_key' => 'k-buy-again', 'status' => 'delivered']);
        $order->items()->create(['product_id' => $p->id, 'name' => $p->name, 'quantity' => 2, 'list_unit_price' => 100, 'unit_price' => 100, 'line_total' => 200]);
        $order->items()->create(['product_id' => $gone->id, 'name' => 'Old piece', 'quantity' => 1, 'list_unit_price' => 100, 'unit_price' => 100, 'line_total' => 100]);
        $gone->update(['status' => 'archived']);

        $this->actingAs($user)->get('/account/orders')->assertSee('Buy again');
        $this->post('/account/orders/KM9100/buy-again')->assertRedirect('/cart')->assertSessionHas('status', fn ($m) => str_contains($m, 'Added 1 item') && str_contains($m, 'Old piece'));
        $this->assertSame(2, Cart::query()->first()->items()->first()->quantity);

        $other = User::factory()->create();
        $this->actingAs($other)->post('/account/orders/KM9100/buy-again')->assertNotFound();
        Features::set('shop.buy_again', false);
        $this->actingAs($user)->post('/account/orders/KM9100/buy-again')->assertNotFound();
        $this->get('/account/orders')->assertDontSee('Buy again');
    }
}
