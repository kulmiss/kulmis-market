<?php

namespace Tests\Feature\Referral;

use App\Jobs\SendCustomerMessage;
use App\Ledger\MoneyChecks;
use App\Membership\MembershipService;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use App\Payments\PaymentService;
use App\Referral\ReferralCodes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class ReferralTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake([SendCustomerMessage::class]);
        $this->admin = User::factory()->superAdmin()->create();
        ShippingZone::query()->create(['name' => 'All', 'cities' => [], 'base_fee' => 20_000, 'is_default' => true]);
    }

    /**
     * After every money test: the "never collapses" rules still hold (books add
     * up, members only earn from money really paid, never more than 35%).
     * A test that breaks them on purpose sets $checkMoney = false.
     */
    protected bool $checkMoney = true;

    protected function tearDown(): void
    {
        try {
            // (Skipped when a test emptied the database, e.g. by running `optimize`.)
            if ($this->checkMoney && $this->status()->isSuccess()) {
                try {
                    $problems = MoneyChecks::problems();
                } catch (QueryException) {
                    $problems = [];
                }
                $this->assertSame([], $problems, 'Money rules broken after '.static::class.'::'.$this->name());
            }
        } finally {
            parent::tearDown();
        }
    }

    /** A member (active) referred by $referrer. */
    protected function member(?User $referrer = null, string $name = 'Abdi'): User
    {
        $u = User::factory()->member()->create(['first_name' => $name, 'referrer_id' => $referrer?->id, 'referred_at' => $referrer ? now() : null]);
        app(ReferralCodes::class)->assign($u);

        return $u->fresh();
    }

    /** @return array{0: User, 1: User, 2: User} [L3, L2, L1] upline chain, all active members */
    protected function chain(): array
    {
        $l3 = $this->member(null, 'Almaz');
        $l2 = $this->member($l3, 'Bekele');
        $l1 = $this->member($l2, 'Chaltu');

        return [$l3, $l2, $l1];
    }

    protected function approve(Model $payable): Payment
    {
        $payments = app(PaymentService::class);
        $p = $payments->submitProof($payable, Payment::BANK, UploadedFile::fake()->image('p.jpg'), 'REF', null);
        $payments->approveProof($p, $this->admin->id);

        return $p->fresh();
    }

    protected function payMembership(User $buyer): Payment
    {
        return $this->approve(app(MembershipService::class)->newPurchase($buyer));
    }

    /** Places an order through the API (guest, or $buyer via token) and pays it. */
    protected function buyProducts(int $price, ?User $buyer = null, ?string $code = null, array $customer = [], int $qty = 1): Order
    {
        $p = Product::factory()->create(['price' => $price, 'stock' => 99, 'weight_grams' => 500]);
        if ($buyer) {
            $this->actingAs($buyer, 'sanctum');
        }
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id, 'quantity' => $qty])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', array_merge([
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Guest', 'phone' => '0977000111',
            'city' => 'Addis Ababa', 'address' => 'CMC', 'referral_code' => $code,
        ], $customer))->assertSuccessful();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->approve($order);
        $this->app['auth']->forgetGuards();

        return $order->fresh();
    }
}
