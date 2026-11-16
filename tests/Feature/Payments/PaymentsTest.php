<?php

namespace Tests\Feature\Payments;

use App\Events\PaymentRefunded;
use App\Events\PaymentSucceeded;
use App\Jobs\SendCustomerMessage;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use App\Notify\Message;
use App\Payments\PaymentService;
use App\Payments\Receipt;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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

    /** Customer sends a Telebirr receipt and staff approve it. */
    private function paidByTransfer(Order $order): Payment
    {
        $this->postJson("/api/v1/orders/{$order->number}/payment-proof", [
            'phone' => '0933445566', 'method' => 'telebirr', 'reference' => 'TB'.$order->id,
            'proof' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertCreated();
        $payment = Payment::query()->latest('id')->firstOrFail();
        $staff = User::factory()->role('finance')->create();
        app(PaymentService::class)->approveProof($payment, $staff->id);

        return $payment->fresh();
    }

    public function test_approved_transfer_posts_ledger_once(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $order = $this->order();
        $payment = $this->paidByTransfer($order);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(0, $payment->fee);
        $this->assertFalse(app(PaymentService::class)->markPaid($payment)); // second time does nothing
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);

        $ledger = app(Ledger::class);
        $this->assertSame(210_000, $ledger->balance(Account::CASH));
        $this->assertSame(210_000, $ledger->balance('income:sales'));
    }

    public function test_proof_needs_matching_phone(): void
    {
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->number}/payment-proof", ['phone' => '0911000000', 'method' => 'telebirr',
            'proof' => UploadedFile::fake()->image('r.jpg')])->assertNotFound();
    }

    public function test_cannot_send_proof_for_a_paid_order(): void
    {
        $order = $this->order();
        $this->paidByTransfer($order);
        $this->postJson("/api/v1/orders/{$order->number}/payment-proof", ['phone' => '0933445566', 'method' => 'telebirr',
            'proof' => UploadedFile::fake()->image('r.jpg')])->assertUnprocessable();
    }

    public function test_old_card_payment_routes_are_gone(): void
    {
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->number}/pay", ['method' => 'chapa', 'phone' => '0933445566'])->assertNotFound();
        $this->postJson('/api/v1/webhooks/chapa', [])->assertNotFound();
        $this->getJson('/api/v1/payments/chapa/callback')->assertNotFound();
        $this->get("/pay/{$order->number}?phone=0933445566")->assertOk()->assertSee('Send receipt')->assertDontSee('Chapa');
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
            'proof' => UploadedFile::fake()->createWithContent('slip.pdf', "%PDF-1.4\n%%EOF")])->assertCreated();

        $this->actingAsAdmin();
        $payment = Payment::query()->firstOrFail();
        $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", ['reason' => 'Amount does not match'])->assertOk();
        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_customer_is_told_why_a_receipt_was_not_accepted_and_can_send_a_new_one(): void
    {
        Storage::fake('local');
        Bus::fake([SendCustomerMessage::class]);
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->number}/payment-proof", ['phone' => '0933445566', 'method' => 'telebirr',
            'proof' => UploadedFile::fake()->image('r.jpg')])->assertCreated();
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/payments/'.Payment::query()->value('id').'/reject', ['reason' => 'Amount does not match'])->assertOk();

        Bus::assertDispatched(SendCustomerMessage::class, fn ($j) => $j->message->type === 'receipt_rejected'
            && $j->message->phone === '+251933445566'
            && str_contains($j->message->body, "order {$order->number}. Reason: Amount does not match. Please send a new receipt"));
        $this->get("/pay/{$order->number}?phone=0933445566")->assertOk()
            ->assertSee('We could not accept your last receipt.')->assertSee('Amount does not match')->assertSee('data-proof-form', false);

        // The message has its own switch, under bank / Telebirr transfers.
        Features::set('messages.receipt_rejected', false);
        $this->assertFalse((new Message('s', 'b', type: 'receipt_rejected'))->allowed());
    }

    public function test_partial_then_full_refund(): void
    {
        Event::fake([PaymentRefunded::class]);
        $payment = $this->paidByTransfer($order = $this->order());
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
        $this->assertSame(['manual', 'manual'], $payment->refunds()->pluck('method')->all());
    }

    public function test_admin_can_list_payments_of_one_order(): void
    {
        Storage::fake('local');
        $a = $this->order();
        $b = $this->order();
        foreach ([$a, $b] as $o) {
            app(PaymentService::class)->submitProof($o, 'telebirr', UploadedFile::fake()->image('p.jpg'), null, null);
        }
        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/payments?order_id='.$a->id)->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.payable_id', $a->id);
    }

    public function test_refund_needs_finance_ability(): void
    {
        $payment = $this->paidByTransfer($this->order());
        $this->actingAsAdmin('fulfilment');
        $this->postJson("/api/v1/admin/payments/{$payment->id}/refund", ['amount' => 1, 'reason' => 'x'])->assertForbidden();
    }

    public function test_receipt_pdf_via_signed_link(): void
    {
        $payment = $this->paidByTransfer($this->order());

        $res = $this->get(Receipt::link($payment->fresh()));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->get('/api/v1/payments/'.$payment->uuid.'/receipt')->assertForbidden(); // unsigned
    }

    public function test_approved_after_cancel_is_recorded_and_flagged(): void
    {
        $order = $this->order();
        $payment = app(PaymentService::class)->submitProof($order, 'telebirr', UploadedFile::fake()->image('p.jpg'), 'TB9', null);
        Order::query()->whereKey($order->id)->update(['status' => 'cancelled']);

        app(PaymentService::class)->approveProof($payment, User::factory()->role('finance')->create()->id);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['action' => 'order.paid_after_cancel']);
    }

    public function test_payment_method_switches(): void
    {
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->number}/payment-options", ['phone' => '0933445566'])
            ->assertOk()->assertJsonCount(2, 'data.methods')->assertJsonPath('data.methods.0.id', 'telebirr');
        Features::set('payments.manual', false);
        $this->postJson("/api/v1/orders/{$order->number}/payment-options", ['phone' => '0933445566'])
            ->assertOk()->assertJsonCount(0, 'data.methods');
    }

    public function test_only_one_receipt_can_wait_for_review(): void
    {
        $order = $this->order();
        $send = fn () => $this->postJson("/api/v1/orders/{$order->number}/payment-proof", ['phone' => '0933445566', 'method' => 'telebirr',
            'proof' => UploadedFile::fake()->image('r.jpg')]);
        $send()->assertCreated();
        $send()->assertUnprocessable()->assertJsonValidationErrors('order'); // double click / second tab
        $this->get("/pay/{$order->number}?phone=0933445566")->assertOk()->assertSee('We have your receipt')->assertDontSee('Send receipt');
        $this->postJson('/api/v1/orders/track', ['number' => $order->number, 'phone' => '0933445566'])
            ->assertOk()->assertJsonPath('data.receipt_under_review', true);
        $this->assertSame(1, Payment::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'the refused copy is not kept');

        // After a rejection the customer can send a better receipt.
        $payment = Payment::query()->sole();
        app(PaymentService::class)->rejectProof($payment, User::factory()->role('finance')->create()->id, 'Blurry');
        $send()->assertCreated();
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_finance_can_see_and_approve_payments_but_not_orders(): void
    {
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->number}/payment-proof", ['phone' => '0933445566', 'method' => 'telebirr',
            'proof' => UploadedFile::fake()->image('r.jpg')])->assertCreated();
        $payment = Payment::query()->sole();
        $this->actingAsAdmin('finance');
        $this->getJson('/api/v1/admin/payments')->assertOk()->assertJsonPath('total', 1);
        $this->get("/api/v1/admin/payments/{$payment->id}/proof")->assertOk();
        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve")->assertOk();
        $this->getJson("/api/v1/admin/orders/{$order->id}")->assertForbidden();
    }
}
