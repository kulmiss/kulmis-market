<?php

namespace Tests\Feature\Shop;

use App\Jobs\SendCustomerMessage;
use App\Models\Commission;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Pricing\PriceCalculator;
use App\Pricing\ProfitCheck;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Referral\ReferralTestCase;

/** Digital products (private files, links that end) and paid add-ons (seats, date, own rates). */
class DigitalAddonsTest extends ReferralTestCase
{
    private function digital(): Product
    {
        $p = Product::factory()->create(['name' => 'Coffee ceremony e-book', 'kind' => Product::DIGITAL, 'stock_mode' => Product::MADE_TO_ORDER,
            'making_days' => null, 'weight_grams' => 0, 'price' => 50_000, 'cost_materials' => 0, 'cost_labour' => 0, 'cost_packaging' => 0]);
        Storage::disk('local')->put('digital/'.$p->id.'/secret.pdf', '%PDF-1.4 hello');
        ProductFile::query()->create(['product_id' => $p->id, 'kind' => 'file', 'title' => 'The e-book', 'path' => 'digital/'.$p->id.'/secret.pdf', 'size' => 14]);
        ProductFile::query()->create(['product_id' => $p->id, 'kind' => 'video', 'title' => 'How-to video', 'video_url' => 'https://videos.example.com/private/abc']);

        return $p;
    }

    public function test_a_digital_product_is_never_sold_out(): void
    {
        $p = Product::factory()->create(['kind' => Product::DIGITAL, 'stock_mode' => Product::STOCK, 'stock' => 0, 'weight_grams' => 900]);
        $this->assertSame(Product::MADE_TO_ORDER, $p->stock_mode);
        $this->assertSame(0, $p->weight_grams);
        $this->assertNull($p->availableStock());
        $this->get('/shop/'.$p->slug)->assertOk()->assertDontSee('Sold out right now');
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->assertSuccessful()->json('token');
        // Checkout: nothing to deliver, so no address to type (fields hidden and filled in).
        $this->withCookie('km_cart', $token)->get('/checkout')->assertOk()->assertSee('No address needed')
            ->assertSee('Digital order: nothing to deliver');
        $physical = Product::factory()->create(['price' => 10_000, 'stock' => 5]);
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/cart/items', ['product_id' => $physical->id])->assertSuccessful();
        $this->withCookie('km_cart', $token)->get('/checkout')->assertOk()->assertDontSee('No address needed');
    }

    public function test_digital_order_no_shipping_private_links_that_end(): void
    {
        $p = $this->digital();
        $buyer = $this->member(null, 'Saba');
        Bus::fake([SendCustomerMessage::class]);
        $this->actingAs($buyer, 'sanctum');
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->getJson('/api/v1/cart?city=Adama')->assertJsonPath('quote.shipping', 0);
        // Buy the digital product and pay.
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'Saba', 'phone' => $buyer->phone, 'city' => 'Adama', 'address' => 'x'])->assertCreated();
        $o = Order::query()->latest('id')->firstOrFail();
        $this->assertSame(0, $o->shipping);
        $this->approve($o);
        $this->assertSame(2, DownloadGrant::query()->where('order_id', $o->id)->count());
        $link = null;
        Bus::assertDispatched(SendCustomerMessage::class, function ($j) use (&$link) {
            if (preg_match('#(/downloads/[A-Za-z0-9]{48})#', $j->message->body, $m)) {
                $link = $m[1];
            }

            return str_contains($j->message->subject, 'Your downloads');
        });
        $this->app['auth']->forgetGuards();

        $res = $this->get((string) $link)->assertOk();
        $this->assertSame('%PDF-1.4 hello', $res->streamedContent());
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->get('/downloads/'.str_repeat('a', 48))->assertStatus(410);

        // Used up after N downloads.
        Settings::set('digital.max_downloads', 5);
        foreach (range(2, 5) as $_) {
            $this->get((string) $link)->assertOk();
        }
        $this->get((string) $link)->assertStatus(410);

        // Refund → links stop. Member page gives fresh links only while usable.
        $g = DownloadGrant::query()->where('order_id', $o->id)->whereHas('file', fn ($q) => $q->where('kind', 'video'))->firstOrFail();
        $this->actingAs($buyer)->get('/account/orders')->assertSee('My downloads')->assertSee('How-to video');
        $this->post('/account/downloads/'.$g->id)->assertRedirect();
        $o->update(['status' => Order::REFUNDED]);
        $this->post('/account/downloads/'.$g->id)->assertNotFound();
        $this->actingAs($this->member(null, 'Other'))->post('/account/downloads/'.$g->id)->assertNotFound();
    }

    public function test_admin_uploads_private_files(): void
    {
        $this->actingAsAdmin();
        $p = Product::factory()->create(['kind' => Product::DIGITAL]);
        $this->post("/api/v1/admin/products/{$p->id}/files", ['title' => 'Guide', 'file' => UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->post("/api/v1/admin/products/{$p->id}/files", ['title' => 'Evil', 'file' => UploadedFile::fake()->create('evil.php', 1, 'text/x-php')], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
        $this->post("/api/v1/admin/products/{$p->id}/files", ['title' => 'Vid', 'video_url' => 'http://insecure.example/v'], ['Accept' => 'application/json'])->assertJsonValidationErrors('video_url');
        $f = ProductFile::query()->firstOrFail();
        Storage::disk('local')->assertExists((string) $f->path);
        $this->assertStringNotContainsString('public', (string) $f->path);
        $this->getJson("/api/v1/admin/products/{$p->id}/files")->assertOk()->assertJsonMissingPath('data.0.path');
    }

    public function test_addons_have_seats_a_date_their_own_rates_and_member_price_choice(): void
    {
        Settings::set('referral.rate.addons.l1', 1_000);
        $addon = Product::factory()->create(['name' => 'Trading class', 'kind' => Product::ADDON, 'price' => 100_000, 'stock' => 1,
            'booking_at' => now()->addWeek(), 'member_discount' => false, 'cost_materials' => 0, 'cost_labour' => 20_000, 'cost_packaging' => 0]);
        $this->get('/shop/'.$addon->slug)->assertSee('Add-on')->assertSee('1 seats left')->assertDontSee('member price');
        $q = app(PriceCalculator::class)->quote([[$addon->load('variants'), null, 1]], true);
        $this->assertSame(100_000, $q->lines[0]->unitPrice); // no member price on this one
        $this->assertSame(1_000 + 300 + 100, app(ProfitCheck::class)->maxProductCommissionBps($addon->fresh()));

        [, , $l1] = $this->chain();
        $buyer = User::factory()->create(['referrer_id' => $l1->id]);
        $this->actingAs($buyer, 'sanctum');
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $addon->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(),
            'first_name' => 'B', 'phone' => '0911000444', 'city' => 'Adama', 'address' => 'x'])->assertCreated();
        $this->approve(Order::query()->latest('id')->firstOrFail());
        $c = Commission::query()->where('recipient_id', $l1->id)->where('level', 1)->firstOrFail();
        $this->assertSame(1_000, $c->rate_bps);       // add-on rate, not the 6% product rate
        $this->assertSame(10_000, $c->amount);
        $this->assertSame(0, $addon->fresh()->stock);  // the only seat is taken
    }
}
