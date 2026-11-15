<?php

namespace Tests\Feature\Platform;

use App\Jobs\SendCustomerMessage;
use App\Ledger\Wallet;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use App\Orders\OrderService;
use App\Payments\PaymentService;
use App\Payments\Receipt;
use App\Support\Currency;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Super Admin → Settings → General: the shop currency, used everywhere; nothing is converted. */
class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ShippingZone::query()->create(['name' => 'All', 'cities' => [], 'base_fee' => 10_000, 'is_default' => true]);
        config(['services.chapa.secret_key' => 'CHASECK_TEST-x']);
    }

    /** @param array<string, string> $values */
    private function useCurrency(array $values): void
    {
        foreach ($values as $k => $v) {
            Settings::set("currency.$k", $v);
        }
    }

    private function dollars(): void
    {
        $this->useCurrency(['code' => 'USD', 'symbol' => '$', 'position' => 'before', 'decimals' => '2']);
    }

    private function order(int $price = 100_000): Order
    {
        $p = Product::query()->where('slug', 'test-basket')->first() ?? Product::factory()->create(['price' => $price, 'stock' => 9, 'slug' => 'test-basket']);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', [
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Selam', 'phone' => '0933445566', 'city' => 'Adama', 'address' => 'x',
        ])->assertCreated();
        $this->flushHeaders();

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_default_stays_etb_exactly_as_before(): void
    {
        $this->assertSame('ETB', Currency::code());
        $this->assertSame('5,000 ETB', Money::format(500_000));
        $this->assertSame('1,234.50 ETB', Money::format(123_450));
        $this->assertSame('-12 ETB', Money::format(-1_200));
        $this->assertSame('1,234.50', Money::format(123_450, false));
    }

    public function test_every_format_option(): void
    {
        $this->dollars();
        $this->assertSame('$5,000.00', Money::format(500_000));
        $this->assertSame('-$0.05', Money::format(-5));

        $this->useCurrency(['code' => 'EUR', 'symbol' => '€', 'position' => 'after_space', 'decimals' => 'auto', 'thousands' => '.', 'decimal_point' => ',']);
        $this->assertSame('1.234,50 €', Money::format(123_450));
        $this->assertSame('5.000 €', Money::format(500_000));

        $this->useCurrency(['symbol' => 'Br', 'position' => 'after', 'thousands' => 'space', 'decimals' => '0']);
        $this->assertSame("1\u{00A0}235Br", Money::format(123_450));

        $this->useCurrency(['symbol' => 'ETB', 'position' => 'before_space', 'thousands' => 'none', 'decimals' => '2', 'decimal_point' => '.']);
        $this->assertSame('ETB 5000.00', Money::format(500_000));
        $this->assertSame("1'000", (function () {
            $this->useCurrency(['thousands' => "'", 'decimals' => '0']);

            return Money::format(100_000, false);
        })());
    }

    public function test_admin_must_confirm_and_nothing_is_converted(): void
    {
        $member = User::factory()->member()->create();
        $product = Product::factory()->create(['price' => 350_000]);
        $before = Wallet::for($member)->available();
        $this->actingAsAdmin();

        $this->putJson('/api/v1/admin/settings', ['settings' => ['currency.code' => 'usd', 'currency.symbol' => '$']])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_currency_change')
            ->assertJsonFragment(['Changing the currency does not convert existing prices, balances or earnings. They keep their numbers and only the symbol and format change (5,000 ETB becomes 5,000 in the new currency). Confirm to save.']);
        $this->assertSame('ETB', Currency::code(), 'not saved without confirmation');

        $this->putJson('/api/v1/admin/settings', ['settings' => ['currency.code' => 'usd', 'currency.symbol' => '$', 'currency.position' => 'before'], 'confirm_currency_change' => true])
            ->assertOk();
        $this->assertSame('USD', Currency::code(), 'code is upper-cased');
        $this->assertSame(350_000, $product->fresh()->price, 'prices are not converted');
        $this->assertSame($before, Wallet::for($member)->available(), 'balances are not converted');
        $this->assertDatabaseHas('activity_log', ['action' => 'setting.updated']);

        // Saving other settings needs no confirmation.
        $this->putJson('/api/v1/admin/settings', ['settings' => ['membership.grace_days' => 4]])->assertOk();
        $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('currency.symbol', '$')->assertJsonPath('currency.code', 'USD');
    }

    public function test_bad_values_are_refused_and_nothing_is_half_saved(): void
    {
        $this->actingAsAdmin();
        $this->putJson('/api/v1/admin/settings', ['settings' => ['currency.code' => 'DOLLAR'], 'confirm_currency_change' => true])
            ->assertJsonValidationErrors('currency.code');
        $this->putJson('/api/v1/admin/settings', ['settings' => ['currency.position' => 'middle'], 'confirm_currency_change' => true])
            ->assertJsonValidationErrors('currency.position');
        $this->putJson('/api/v1/admin/settings', ['settings' => ['currency.symbol' => '€', 'currency.thousands' => '.'], 'confirm_currency_change' => true])
            ->assertJsonValidationErrors('settings.currency.decimal_point');
        $this->assertSame('ETB', Settings::string('currency.symbol'), 'rolled back, and the cache was not left stale');
        $this->assertSame(',', Settings::string('currency.thousands'));
    }

    public function test_storefront_api_messages_and_receipt_use_the_setting(): void
    {
        Storage::fake('local');
        Bus::fake([SendCustomerMessage::class]);
        $this->dollars();
        $order = $this->order();

        $this->get('/shop/test-basket')->assertOk()->assertSee('$1,000.00')->assertDontSee('1,000 ETB')
            ->assertSee('"priceCurrency":"USD"', false)->assertSee('Prices in USD')->assertSee('name="km-currency"', false);
        $this->getJson('/api/v1/ping')->assertJsonPath('currency', 'USD')->assertJsonPath('currency_format.symbol', '$')
            ->assertJsonPath('currency_format.position', 'before');
        $this->getJson('/api/v1/products/test-basket')->assertJsonPath('data.currency', 'USD');

        app(OrderService::class)->markPaid($order);
        Bus::assertDispatched(SendCustomerMessage::class, fn ($job) => str_contains($job->message->body, '$1,100.00'));

        // Manual transfers still work in any currency; the receipt shows the new format.
        $second = $this->order();
        $p = app(PaymentService::class)->submitProof($second, Payment::BANK, UploadedFile::fake()->image('p.jpg'), 'REF', null);
        $this->assertSame('USD', $p->currency);
        $html = view('pdf.receipt', ['payment' => $p, 'payable' => $second, 'order' => $second->load('items'),
            'customer' => $second->payableCustomer(), 'title' => $second->payableTitle()])->render();
        $this->assertStringContainsString('$1,000.00', $html);
        $this->assertNotEmpty(Receipt::link($p));
    }

    public function test_chapa_checkout_is_blocked_with_a_clear_message_when_it_cannot_take_the_currency(): void
    {
        Http::fake();
        $this->dollars();
        $order = $this->order();

        $this->postJson("/api/v1/orders/{$order->number}/pay", ['method' => 'chapa', 'phone' => '0933445566'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment')
            ->assertJsonFragment(['Online payment through Chapa only works in ETB, and this shop now uses USD. Please pay by bank or Telebirr transfer, or contact us.']);
        Http::assertNothingSent();
        $this->assertSame(0, Payment::query()->count());

        $page = $this->get("/pay/{$order->number}?phone=0933445566")->assertOk();
        $page->assertDontSee('Pay with Chapa')->assertSee('only works in ETB')->assertSee('Transfer and upload the receipt');
        $this->get('/signals')->assertOk()->assertDontSee('with Chapa</button>', false)->assertSee('only works in ETB');
    }

    public function test_chapa_gets_the_shop_currency_when_it_supports_it_and_must_answer_in_it(): void
    {
        config(['services.chapa.currencies' => ['ETB', 'USD']]);
        $this->dollars();
        Http::fake([
            'api.chapa.co/v1/transaction/initialize' => Http::response(['status' => 'success', 'data' => ['checkout_url' => 'https://checkout.chapa.co/abc']]),
            'api.chapa.co/v1/transaction/verify/*' => Http::response(['status' => 'success', 'data' => [
                'status' => 'success', 'amount' => '9999.00', 'currency' => 'ETB', 'reference' => 'AP1', 'charge' => '0']]),
        ]);
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->number}/pay", ['method' => 'chapa', 'phone' => '0933445566'])->assertCreated();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'initialize') && $r['currency'] === 'USD');

        // Chapa answers in another currency: never marked paid.
        $payment = Payment::query()->latest('id')->firstOrFail();
        $this->assertSame('USD', $payment->currency);
        $this->assertFalse(app(PaymentService::class)->confirmChapa($payment->tx_ref)->isPaid());
    }
}
