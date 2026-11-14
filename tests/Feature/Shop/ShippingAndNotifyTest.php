<?php

namespace Tests\Feature\Shop;

use App\Jobs\SendCustomerMessage;
use App\Mail\PlainMessage;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use App\Notify\Message;
use App\Notify\Sms\SmsGateway;
use App\Orders\OrderService;
use App\Pricing\PricingException;
use App\Shipping\ZoneShippingQuoter;
use App\Support\Features;
use App\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShippingAndNotifyTest extends TestCase
{
    use RefreshDatabase;

    private ShippingZone $addis;

    private ShippingZone $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addis = ShippingZone::query()->create(['name' => 'Addis Ababa', 'cities' => ['Addis Ababa'], 'base_fee' => 15_000, 'per_kg_fee' => 5_000]);
        $this->other = ShippingZone::query()->create(['name' => 'Other cities', 'cities' => [], 'base_fee' => 40_000, 'per_kg_fee' => 10_000, 'is_default' => true]);
    }

    private function order(array $customer = [], ?User $user = null, int $price = 100_000, int $weight = 800): Order
    {
        $p = Product::factory()->create(['price' => $price, 'weight_grams' => $weight]);
        if ($user) {
            $this->actingAs($user, 'sanctum');
        }
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', array_merge([
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Dawit', 'phone' => '0922000111', 'email' => 'd@example.com',
            'city' => 'Addis Ababa', 'address' => 'Piassa',
        ], $customer))->assertCreated();

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_fee_by_zone_and_weight(): void
    {
        $q = app(ZoneShippingQuoter::class);
        $this->assertSame(15_000, $q->quote(null, 'addis ababa', 1000));       // included 1 kg
        $this->assertSame(20_000, $q->quote(null, 'Addis Ababa', 1001));       // +1 started kg
        $this->assertSame(50_000, $q->quote(null, 'Gondar', 1800));            // default zone
        $this->assertSame(15_000, $q->quote($this->addis->id, 'Anything', 500)); // explicit zone wins
    }

    public function test_unknown_city_without_default_zone_is_refused(): void
    {
        $this->other->update(['is_default' => false, 'is_active' => false]);
        $this->expectException(PricingException::class);
        app(ZoneShippingQuoter::class)->quote(null, 'Jinka', 500);
    }

    public function test_checkout_adds_shipping_and_members_over_3000_ship_free(): void
    {
        $guest = $this->order(['city' => 'Hawassa'], null, 400_000);
        $this->assertSame(40_000, $guest->shipping);
        $this->assertSame(440_000, $guest->total);

        $member = $this->order(['city' => 'Hawassa'], User::factory()->member()->create(), 400_000);
        $this->assertSame(40_000, $member->shipping);
        $this->assertSame(40_000, $member->shipping_discount);
        $this->assertSame(340_000, $member->total);
    }

    public function test_free_shipping_switch_off(): void
    {
        Features::set('shop.free_shipping', false);
        $member = $this->order(['city' => 'Hawassa'], User::factory()->member()->create(), 400_000);
        $this->assertSame(380_000, $member->total);
    }

    public function test_status_flow_sends_messages_on_every_step(): void
    {
        Bus::fake([SendCustomerMessage::class]);
        $this->actingAsAdmin();
        $order = $this->order();
        app(OrderService::class)->markPaid($order);
        $courier = Courier::query()->create(['name' => 'EMS', 'tracking_url' => 'https://ems.example/t?n={number}']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'making'])->assertOk();
        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'shipped'])->assertUnprocessable()->assertJsonValidationErrors('courier_id');
        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'shipped', 'courier_id' => $courier->id, 'tracking_number' => 'EE123ET'])->assertOk();
        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'making'])->assertUnprocessable();

        Bus::assertDispatchedTimes(SendCustomerMessage::class, 4); // paid, making, shipped, delivered
        Bus::assertDispatched(SendCustomerMessage::class, fn ($job) => str_contains($job->message->body, 'EMS')
            && str_contains($job->message->body, 'EE123ET') && str_contains($job->message->body, 'https://ems.example/t?n=EE123ET'));
        $this->assertNotNull($order->fresh()->shipped_at);
    }

    public function test_admin_cannot_mark_paid_or_refund_through_status(): void
    {
        $this->actingAsAdmin();
        $order = $this->order();
        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'paid'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'refunded'])->assertUnprocessable();
    }

    public function test_message_job_uses_each_channel_and_survives_failures(): void
    {
        config(['services.telegram.bot_token' => 'TEST']);
        Mail::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $sms = new class implements SmsGateway
        {
            public array $sent = [];

            public function send(string $to, string $message): void
            {
                throw new \RuntimeException('provider down');
            }
        };
        $this->app->instance(SmsGateway::class, $sms);

        $job = new SendCustomerMessage(new Message('Hi', 'Body', '+251911000000', 'x@example.com', 12345));
        $job->handle($sms, app(TelegramClient::class));

        Mail::assertSent(PlainMessage::class);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && $r['chat_id'] === 12345);
    }

    public function test_channels_follow_feature_switches(): void
    {
        Mail::fake();
        Features::set('notifications.email', false);
        $job = new SendCustomerMessage(new Message('Hi', 'Body', null, 'x@example.com'));
        $job->handle(app(SmsGateway::class), app(TelegramClient::class));
        Mail::assertNothingSent();
    }

    public function test_bulk_status_reports_failures(): void
    {
        Bus::fake([SendCustomerMessage::class]);
        $this->actingAsAdmin();
        $paid = $this->order();
        app(OrderService::class)->markPaid($paid);
        $unpaid = $this->order();

        $res = $this->postJson('/api/v1/admin/orders/bulk-status', ['ids' => [$paid->id, $unpaid->id], 'status' => 'making'])->assertOk();
        $this->assertSame([$paid->number], $res->json('updated'));
        $this->assertArrayHasKey($unpaid->number, $res->json('failed'));
    }

    public function test_admin_zone_and_courier_crud_and_public_zones(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/shipping-zones', ['name' => 'Amhara', 'cities' => ['Bahir Dar', 'Gondar'], 'base_fee' => 30_000])->assertCreated();
        $this->postJson('/api/v1/admin/couriers', ['name' => 'HudHud', 'tracking_url' => 'not a url'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/couriers', ['name' => 'HudHud'])->assertCreated();
        $this->getJson('/api/v1/shipping/zones')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(30_000, app(ZoneShippingQuoter::class)->quote(null, 'Gondar', 900));
    }

    public function test_admin_orders_filters(): void
    {
        $this->actingAsAdmin();
        $a = $this->order(['phone' => '0911111111']);
        $this->order(['phone' => '0922222222']);
        $this->getJson('/api/v1/admin/orders?q=0911111111')->assertJsonPath('total', 0);
        $this->getJson('/api/v1/admin/orders?q=911111111')->assertJsonPath('total', 1)->assertJsonPath('data.0.number', $a->number);
        $this->getJson('/api/v1/admin/orders?status=pending_payment')->assertJsonPath('total', 2);
    }
}
