<?php

namespace Tests\Feature\Payments;

use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Payments\ChapaGateway;
use App\Payments\PaymentService;
use App\Payments\Receipt;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.chapa.secret_key' => 'CHASECK_TEST-x', 'services.chapa.webhook_secret' => self::SECRET]);
        ShippingZone::query()->create(['name' => 'All', 'cities' => [], 'base_fee' => 10_000, 'is_default' => true]);
    }

    private function order(int $price = 100_000): Order
    {
        $p = Product::factory()->create(['price' => $price, 'stock' => 5]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'quantity' => 2])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', [
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Selam', 'phone' => '0933445566',
            'city' => 'Adama', 'address' => 'Kebele 04',
        ])->assertCreated();

        return Order::query()->latest('id')->firstOrFail(); // total 2,100 ETB
    }

    private function fakeChapa(string $status = 'success', ?string $amount = '2100.00', string $currency = 'ETB'): void
    {
        Http::fake([
            'api.chapa.co/v1/transaction/initialize' => Http::response(['status' => 'success', 'data' => ['checkout_url' => 'https://checkout.chapa.co/abc']]),
            'api.chapa.co/v1/transaction/verify/*' => Http::response(['status' => 'success', 'data' => [
                'status' => $status, 'amount' => $amount, 'currency' => $currency, 'reference' => 'APxyz', 'charge' => '73.50', 'mobile' => '0933445566']]),
            'api.chapa.co/v1/refund/*' => Http::response(['status' => 'success', 'data' => ['reference' => 'RF1']]),
        ]);
    }

    private function startChapa(Order $order): Payment
    {
        $this->postJson("/api/v1/orders/{$order->number}/pay", ['method' => 'chapa', 'phone' => '0933445566'])
            ->assertCreated()->assertJsonPath('data.checkout_url', 'https://checkout.chapa.co/abc');

        return Payment::query()->latest('id')->firstOrFail();
    }

    private function webhook(string $txRef, array $headers = []): TestResponse
    {
        $body = json_encode(['event' => 'charge.success', 'tx_ref' => $txRef, 'status' => 'success']);

        return $this->call('POST', '/api/v1/webhooks/chapa', [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CHAPA_SIGNATURE' => hash_hmac('sha256', (string) $body, self::SECRET),
        ], $headers), $body);
    }

    public function test_chapa_init_sends_correct_amount_and_phone(): void
    {
        $this->fakeChapa();
        $this->startChapa($this->order());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'initialize') && $r['amount'] === '2100.00'
            && $r['currency'] === 'ETB' && $r['phone_number'] === '0933445566' && str_starts_with($r['tx_ref'], 'KM-'));
    }

    public function test_pay_needs_matching_phone(): void
    {
        $this->fakeChapa();
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->number}/pay", ['method' => 'chapa', 'phone' => '0911000000'])->assertNotFound();
    }

    public function test_signed_webhook_marks_paid_once_with_ledger_and_event(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $this->fakeChapa();
        $order = $this->order();
        $payment = $this->startChapa($order);

        $this->webhook($payment->tx_ref)->assertOk();
        $this->webhook($payment->tx_ref)->assertOk(); // duplicate delivery
        $this->getJson('/api/v1/payments/chapa/callback?trx_ref='.$payment->tx_ref)->assertJson(['status' => 'paid']);

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame(7_350, $order->payment_fee);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);

        $ledger = app(Ledger::class);
        $this->assertSame(210_000 - 7_350, $ledger->balance(Account::CASH));
        $this->assertSame(210_000, $ledger->balance('income:sales'));
        $this->assertSame('0933445566', $payment->fresh()->payer_account);
    }

    public function test_bad_signature_is_rejected_and_gives_no_value(): void
    {
        $this->fakeChapa();
        $payment = $this->startChapa($this->order());
        $this->webhook($payment->tx_ref, ['HTTP_X_CHAPA_SIGNATURE' => 'nope'])->assertUnauthorized();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_alternative_chapa_signature_header_is_accepted(): void
    {
        $this->assertTrue(app(ChapaGateway::class)->validSignature('{}', null, hash_hmac('sha256', self::SECRET, self::SECRET)));
        $this->assertFalse(app(ChapaGateway::class)->validSignature('{}', null, null));
    }

    public function test_signed_webhook_for_unpaid_transaction_gives_no_value(): void
    {
        $this->fakeChapa('pending');
        $payment = $this->startChapa($this->order());
        $this->webhook($payment->tx_ref)->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_underpaid_or_wrong_currency_is_never_auto_accepted(): void
    {
        $this->fakeChapa('success', '100.00');
        $payment = $this->startChapa($order = $this->order());
        $this->webhook($payment->tx_ref)->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertTrue($payment->fresh()->meta['mismatch']);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_cannot_pay_twice_for_a_paid_order(): void
    {
        $this->fakeChapa();
        $payment = $this->startChapa($order = $this->order());
        $this->webhook($payment->tx_ref);
        $this->postJson("/api/v1/orders/{$order->number}/pay", ['method' => 'chapa', 'phone' => '0933445566'])->assertUnprocessable();
    }

    public function test_manual_proof_flow_approve(): void
    {
        Storage::fake('local');
        Event::fake([PaymentSucceeded::class]);
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->number}/payment-proof", [
            'phone' => '0933445566', 'method' => 'telebirr', 'reference' => 'TB123',
            'proof' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertCreated()->assertJsonPath('data.status', 'awaiting_review');

        $payment = Payment::query()->firstOrFail();
        Storage::disk('local')->assertExists((string) $payment->proof_path);

        // Not auto-cancelled while waiting for review.
        $this->travel(72)->hours();
        $this->artisan('orders:cancel-unpaid');
        $this->assertSame('pending_payment', $order->fresh()->status);

        $this->actingAsAdmin();
        $this->get("/api/v1/admin/payments/{$payment->id}/proof")->assertOk();
        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve")->assertUnprocessable();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(0, $payment->fresh()->fee);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_manual_proof_reject_and_validation(): void
    {
        Storage::fake('local');
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->number}/payment-proof", ['phone' => '0933445566', 'method' => 'bank_transfer',
            'proof' => UploadedFile::fake()->create('x.exe', 10)])->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$order->number}/payment-proof", ['phone' => '0933445566', 'method' => 'bank_transfer',
            'proof' => UploadedFile::fake()->create('slip.pdf', 100, 'application/pdf')])->assertCreated();

        $this->actingAsAdmin();
        $payment = Payment::query()->firstOrFail();
        $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", ['reason' => 'Amount does not match'])->assertOk();
        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_partial_then_full_refund(): void
    {
        Event::fake([PaymentRefunded::class]);
        $this->fakeChapa();
        $payment = $this->startChapa($order = $this->order());
        $this->webhook($payment->tx_ref);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/payments/{$payment->id}/refund", ['amount' => 50_000, 'reason' => 'One item damaged'])
            ->assertOk()->assertJsonPath('data.status', 'partially_refunded');
        $this->assertSame('paid', $order->fresh()->status);
        $this->postJson("/api/v1/admin/payments/{$payment->id}/refund", ['amount' => 200_000, 'reason' => 'too much'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/payments/{$payment->id}/refund", ['amount' => 160_000, 'reason' => 'Customer cancelled', 'restock' => true])
            ->assertOk()->assertJsonPath('data.status', 'refunded');

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame(210_000, $order->refunded_amount);
        $this->assertSame(5, Product::query()->firstOrFail()->stock);
        $this->assertSame(0, app(Ledger::class)->balance('income:sales'));
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/refund/'));
    }

    public function test_refund_needs_finance_ability(): void
    {
        $this->actingAsAdmin('fulfilment');
        $this->fakeChapa();
        $payment = $this->startChapa($this->order());
        $this->postJson("/api/v1/admin/payments/{$payment->id}/refund", ['amount' => 1, 'reason' => 'x'])->assertForbidden();
    }

    public function test_receipt_pdf_via_signed_link(): void
    {
        $this->fakeChapa();
        $payment = $this->startChapa($this->order());
        $this->webhook($payment->tx_ref);

        $res = $this->get(Receipt::link($payment->fresh()));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->get('/api/v1/payments/'.$payment->uuid.'/receipt')->assertForbidden(); // unsigned
    }

    public function test_paid_after_auto_cancel_is_recorded_and_flagged(): void
    {
        $this->fakeChapa();
        $order = $this->order();
        $payment = app(PaymentService::class)->startChapa($order, 'https://x');
        Order::query()->whereKey($order->id)->update(['status' => 'cancelled']);

        $this->webhook($payment->tx_ref)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['action' => 'order.paid_after_cancel']);
    }

    public function test_payment_method_switches(): void
    {
        $order = $this->order();
        Features::set('payments.chapa', false);
        $this->postJson("/api/v1/orders/{$order->number}/pay", ['method' => 'chapa', 'phone' => '0933445566'])->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->number}/payment-options", ['phone' => '0933445566'])
            ->assertOk()->assertJsonCount(2, 'data.methods');
    }
}
