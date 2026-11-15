<?php

namespace Tests\Feature\Shop;

use App\Jobs\SendCustomerMessage;
use App\Ledger\Ledger;
use App\Models\Cart;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use App\Orders\OrderService;
use App\Payments\PaymentService;
use App\Shop\GiftCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Gift cards: secret codes, spent at checkout like the wallet, balance in the ledger. */
class GiftCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendCustomerMessage::class]);
        $this->admin = User::factory()->superAdmin()->create();
        ShippingZone::query()->create(['name' => 'All', 'cities' => [], 'base_fee' => 10_000, 'is_default' => true]);
    }

    private function buyOnline(int $amount = 100_000): string
    {
        $this->post('/api/v1/gift-cards', ['amount' => $amount, 'buyer_name' => 'Abel', 'buyer_phone' => '0911000111', 'recipient_name' => 'Sara',
            'recipient_contact' => 'sara@example.com', 'method' => 'telebirr', 'proof' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $card = GiftCard::query()->latest('id')->firstOrFail();
        app(PaymentService::class)->approveProof($card->payments()->firstOrFail(), $this->admin->id);
        $code = null;
        Bus::assertDispatched(SendCustomerMessage::class, function ($j) use (&$code) {
            if (preg_match('/Code: ([A-Z0-9-]{19})/', $j->message->body, $m)) {
                $code = $m[1];
            }

            return $j->message->email === 'sara@example.com';
        });

        return (string) $code;
    }

    private function checkout(Product $p, ?string $code): Order
    {
        $this->flushHeaders();
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        if ($code) {
            $this->withHeaders(['X-Cart-Token' => $token])->putJson('/api/v1/cart/gift-card', ['code' => strtolower($code)])->assertOk()
                ->assertJsonPath('gift_card.last4', substr(str_replace('-', '', $code), -4));
        }
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(), 'first_name' => 'A',
            'phone' => '0911000'.random_int(100, 999), 'city' => 'Adama', 'address' => 'x'])->assertCreated();

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_bought_online_code_is_secret_and_pays_part_of_an_order(): void
    {
        $code = $this->buyOnline(100_000);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $code);
        $card = GiftCard::query()->firstOrFail();
        $this->assertSame('active', $card->status);
        $this->assertNotSame($code, $card->code_hash);
        $this->assertSame(0, DB::table('gift_cards')->where('code_hash', 'like', '%'.str_replace('-', '', $code).'%')->count());
        $this->assertSame(100_000, app(Ledger::class)->balance(GiftCard::ACCOUNT));

        $order = $this->checkout(Product::factory()->create(['price' => 200_000]), $code); // 2,000 + 100 shipping
        $this->assertSame(100_000, $order->gift_card_used);
        $this->assertSame(110_000, $order->amountDue());
        $this->assertSame('pending_payment', $order->status);
        $this->assertSame(0, $card->fresh()->balance);
        $this->assertSame('used', $card->fresh()->status);
        $this->assertSame(0, app(Ledger::class)->balance(GiftCard::ACCOUNT));

        // Not paid in time → cancelled: the card gets its money back.
        app(OrderService::class)->cancel($order->fresh(), 'not paid');
        $this->assertSame(100_000, $card->fresh()->balance);
        $this->assertSame('active', $card->fresh()->status);
        $this->assertSame(100_000, app(Ledger::class)->balance(GiftCard::ACCOUNT));
    }

    public function test_full_payment_by_card_and_refund_goes_back_on_the_card(): void
    {
        $code = $this->buyOnline(500_000);
        $order = $this->checkout(Product::factory()->create(['price' => 200_000]), $code);
        $this->assertSame('paid', $order->fresh()->status);
        $card = GiftCard::query()->firstOrFail();
        $this->assertSame(500_000 - 210_000, $card->balance);

        $pay = Payment::query()->where('provider', Payment::GIFT_CARD)->firstOrFail();
        app(PaymentService::class)->refund($pay, 210_000, 'returned', $this->admin->id);
        $this->assertSame(500_000, $card->fresh()->balance);
        $this->assertSame(500_000, app(Ledger::class)->balance(GiftCard::ACCOUNT));
    }

    public function test_one_card_cannot_be_spent_twice_and_guessing_is_slow(): void
    {
        $code = $this->buyOnline(100_000);
        $card = GiftCard::query()->firstOrFail();
        $p = Product::factory()->create(['price' => 200_000]);
        $a = $this->checkout($p, null);
        $b = $this->checkout($p, null);
        app(GiftCards::class)->apply($a, $card->id);
        $this->assertNull(app(GiftCards::class)->apply($b, $card->id)); // nothing left
        $this->assertSame(0, $b->fresh()->gift_card_used);

        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        foreach (range(1, 5) as $_) {
            $this->withHeaders(['X-Cart-Token' => $token])->putJson('/api/v1/cart/gift-card', ['code' => 'AAAA-BBBB-CCCC-DDDD'])->assertJsonValidationErrors('code');
        }
        $this->withHeaders(['X-Cart-Token' => $token])->putJson('/api/v1/cart/gift-card', ['code' => $code])
            ->assertJsonPath('errors.code.0', 'Too many tries. Please wait a few minutes.');
        $this->assertNull(Cart::query()->where('token', $token)->value('gift_card_id'));
    }

    public function test_sold_in_the_shop_expired_and_disabled_cards(): void
    {
        $this->actingAs($this->admin);
        $this->admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $code = $this->postJson('/api/v1/admin/gift-cards/sell', ['amount' => 200_000, 'buyer_name' => 'Walk-in'])->assertCreated()->json('data.code');
        $this->assertSame(200_000, app(Ledger::class)->balance('asset:cash'));
        $card = GiftCard::query()->firstOrFail();
        $this->getJson('/api/v1/admin/gift-cards')->assertOk()->assertJsonPath('owed', 200_000)->assertJsonMissing(['code_hash' => $card->code_hash]);

        $this->app['auth']->forgetGuards();
        $p = Product::factory()->create();
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $card->update(['expires_at' => now()->subDay()]);
        $this->withHeaders(['X-Cart-Token' => $token])->putJson('/api/v1/cart/gift-card', ['code' => $code])->assertJsonValidationErrors('code');
        $card->update(['expires_at' => null, 'status' => 'disabled']);
        $this->withHeaders(['X-Cart-Token' => $token])->putJson('/api/v1/cart/gift-card', ['code' => $code])->assertJsonValidationErrors('code');
    }
}
