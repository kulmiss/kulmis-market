<?php

namespace Tests\Feature\Shop;

use App\Jobs\SendCustomerMessage;
use App\Models\Commission;
use App\Models\Coupon;
use App\Models\CustomRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Notify\NotifyPrefs;
use App\Payments\PaymentService;
use App\Shop\Winback;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Referral\ReferralTestCase;

/** Pre-orders and custom orders (deposit now, rest before shipping) and win-back offers. */
class DepositsWinbackTest extends ReferralTestCase
{
    private function proof(Order $order): Payment
    {
        return app(PaymentService::class)->submitProof($order->fresh(), Payment::TELEBIRR, UploadedFile::fake()->image('r.jpg'), 'TX', null);
    }

    public function test_preorder_deposit_then_rest_and_team_earnings_only_at_the_end(): void
    {
        [, , $l1] = $this->chain();
        $p = Product::factory()->create(['price' => 100_000, 'stock' => 5, 'preorder_deposit_bps' => 3_000, 'preorder_ships_on' => now()->addMonth()]);
        $this->get('/shop/'.$p->slug)->assertSee('Pre-order')->assertSee('pay 30% now');
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(), 'first_name' => 'G',
            'phone' => '0911000555', 'city' => 'Adama', 'address' => 'x', 'referral_code' => $l1->referral_code])->assertCreated();
        $o = Order::query()->firstOrFail();
        $this->assertSame(100_000 + 20_000 - 70_000, $o->deposit_amount); // shipping now, 30% of the pre-order item
        $this->get('/pay/'.$o->number.'?phone=0911000555')->assertSee('deposit now');

        $first = $this->proof($o);
        $this->assertSame(50_000, $first->amount);
        app(PaymentService::class)->approveProof($first, $this->admin->id);
        $o->refresh();
        $this->assertSame(Order::PENDING_PAYMENT, $o->status);
        $this->assertSame(50_000, $o->deposit_paid);
        $this->assertSame(70_000, $o->amountDue());
        $this->assertSame(0, Commission::query()->count(), 'no team earnings on a deposit');

        // Not cancelled for being "unpaid".
        $this->travel(3)->days();
        $this->artisan('orders:cancel-unpaid')->assertSuccessful();
        $this->assertSame(Order::PENDING_PAYMENT, $o->fresh()->status);

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/orders/{$o->id}/ask-rest")->assertOk();
        $this->app['auth']->forgetGuards();
        $rest = $this->proof($o);
        $this->assertSame(70_000, $rest->amount);
        app(PaymentService::class)->approveProof($rest, $this->admin->id);
        $this->assertSame(Order::PAID, $o->fresh()->status);
        $this->assertSame(6_000, Commission::query()->where('recipient_id', $l1->id)->value('amount')); // on the full 1,000 ETB
    }

    public function test_custom_order_request_quote_deposit_and_decline(): void
    {
        Storage::fake('public');
        Bus::fake([SendCustomerMessage::class]);
        $this->get('/custom-order')->assertOk()->assertSee('Ask for a custom piece');
        $this->post('/api/v1/custom-requests', ['name' => 'Tsion', 'phone' => '0911000666', 'city' => 'Hawassa', 'address' => 'Piassa',
            'description' => 'A big green mesob for my sister\'s wedding 💚', 'budget' => '4000', 'photos' => [UploadedFile::fake()->image('a.jpg')]],
            ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/custom-requests', ['name' => 'x', 'phone' => '0911', 'city' => 'x', 'address' => 'x', 'description' => 'short'],
            ['Accept' => 'application/json'])->assertJsonValidationErrors(['description']);
        $req = CustomRequest::query()->firstOrFail();
        $this->assertSame(400_000, $req->budget);
        Storage::disk('public')->assertExists($req->photos[0]);

        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/custom-requests')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/admin/custom-requests/{$req->id}/quote", ['price' => 450_000, 'cost' => 150_000, 'making_days' => 10])->assertOk()
            ->assertJsonPath('data.deposit', 225_000);
        $o = Order::query()->firstOrFail();
        $this->assertSame(450_000, $o->total);
        $this->assertSame(150_000, $o->items()->first()->unit_cost);
        Bus::assertDispatched(SendCustomerMessage::class, fn ($j) => str_contains($j->message->body, '/pay/'.$o->number));
        $this->app['auth']->forgetGuards();
        $p = $this->proof($o);
        $this->assertSame(225_000, $p->amount);

        // Quotes wait 7 days, not the normal hours.
        $this->travel(3)->days();
        $this->artisan('orders:cancel-unpaid');
        $this->assertSame(Order::PENDING_PAYMENT, $o->fresh()->status);

        $other = CustomRequest::query()->create(['name' => 'B', 'phone' => '+251911000777', 'city' => 'x', 'address' => 'y', 'description' => 'Something we cannot make']);
        $this->flushSession();
        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/custom-requests/{$other->id}/decline", ['reason' => 'Too big for our looms'])->assertOk();
        $this->assertSame('declined', $other->fresh()->status);
    }

    public function test_win_back_offer_once_only_for_old_customers_who_want_offers(): void
    {
        $old = $this->member(null, 'Old');
        $recent = $this->member(null, 'Recent');
        $quiet = $this->member(null, 'Quiet');
        foreach ([[$old, 90], [$recent, 5], [$quiet, 90]] as [$u, $days]) {
            Order::query()->create(['number' => 'KM'.random_int(10000, 99999), 'user_id' => $u->id, 'first_name' => 'A', 'phone' => '+251911000001',
                'city' => 'A', 'address' => 'B', 'list_subtotal' => 1, 'merchandise_total' => 1, 'total' => 1, 'checkout_key' => uniqid('k', true),
                'status' => Order::DELIVERED, 'paid_at' => now()->subDays($days), 'created_at' => now()->subDays($days)]);
        }
        NotifyPrefs::set($quiet, ['offers' => false]);

        $this->assertSame(1, app(Winback::class)->run());
        $this->assertSame(0, app(Winback::class)->run());
        $c = Coupon::query()->firstOrFail();
        $this->assertStringStartsWith('BACK', $c->code);
        $this->assertSame(1_000, $c->value);
        $this->assertSame(1, $c->max_uses);
        $this->assertNotNull($old->fresh()->winback_sent_at);
        Bus::assertDispatched(SendCustomerMessage::class, fn ($j) => $j->message->type === 'winback' && str_contains($j->message->body, $c->code));
        unset($recent, $quiet);
    }
}
