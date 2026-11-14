<?php

namespace Tests\Feature\Membership;

use App\Events\MembershipEnded;
use App\Jobs\SendCustomerMessage;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Membership\MembershipService;
use App\Models\Membership;
use App\Models\MembershipPurchase;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentService;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake([SendCustomerMessage::class]);
    }

    private function payMonth(User $user): Payment
    {
        $service = app(PaymentService::class);
        $purchase = app(MembershipService::class)->newPurchase($user);
        $payment = $service->submitProof($purchase, Payment::TELEBIRR, UploadedFile::fake()->image('p.jpg'), 'TB1', null);
        $service->approveProof($payment, $this->admin()->id);

        return $payment->fresh();
    }

    private function admin(): User
    {
        return User::query()->where('role', 'super_admin')->first() ?? User::factory()->superAdmin()->create();
    }

    private function membership(User $u): Membership
    {
        return Membership::query()->where('user_id', $u->id)->firstOrFail();
    }

    public function test_register_login_and_me(): void
    {
        $this->postJson('/api/v1/auth/register', ['first_name' => 'Abebe', 'last_name' => 'Kebede', 'phone' => '0912345678', 'password' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $token = $this->postJson('/api/v1/auth/register', ['first_name' => 'Abebe', 'last_name' => 'Kebede', 'phone' => '0912345678', 'password' => 'buna2026x'])
            ->assertCreated()->assertJsonPath('user.phone', '+251912345678')->assertJsonPath('user.is_member', false)->json('token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.first_name', 'Abebe');

        $this->postJson('/api/v1/auth/register', ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '+251912345678', 'password' => 'buna2026x'])
            ->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/auth/login', ['login' => '0912 345 678', 'password' => 'buna2026x'])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/v1/auth/login', ['login' => '0912345678', 'password' => 'wrong'])->assertUnprocessable();
    }

    public function test_staff_cannot_get_api_tokens(): void
    {
        User::factory()->superAdmin()->create(['phone' => '+251911111111', 'password' => 'admin-pass-123']);
        $this->postJson('/api/v1/auth/login', ['login' => '0911111111', 'password' => 'admin-pass-123'])->assertUnprocessable();
    }

    public function test_paying_activates_one_month_with_grace(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        $payment = $this->payMonth($user);

        $this->assertSame(500_000, $payment->amount);
        $m = $this->membership($user);
        $this->assertSame('active', $m->status);
        $this->assertTrue($m->current_period_end->eq(now()->addMonthNoOverflow()));
        $this->assertTrue($m->grace_ends_at->eq(now()->addMonthNoOverflow()->addDays(3)));
        $this->assertTrue($user->fresh()->isActiveMember());
        $this->assertSame(500_000, app(Ledger::class)->balance('income:memberships'));
    }

    public function test_renewing_early_extends_from_end_and_stays_continuous(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        $this->payMonth($user);
        $since = $this->membership($user)->continuous_since;
        $firstEnd = $this->membership($user)->current_period_end;

        $this->travel(25)->days();
        $this->payMonth($user);
        $m = $this->membership($user);
        $this->assertTrue($m->current_period_end->eq($firstEnd->copy()->addMonthNoOverflow()));
        $this->assertTrue($m->continuous_since->eq($since));
        $this->assertSame(2, $m->periods_paid);
    }

    public function test_renewing_in_grace_is_still_continuous(): void
    {
        $user = User::factory()->create();
        $this->payMonth($user);
        $since = $this->membership($user)->continuous_since;
        $this->travel(32)->days(); // past period end, inside 3-day grace
        app(MembershipService::class)->sweep();
        $this->assertSame('grace', $this->membership($user)->status);
        $this->assertTrue($user->fresh()->isActiveMember(), 'benefits continue in grace');

        $this->payMonth($user);
        $this->assertTrue($this->membership($user)->continuous_since->eq($since));
    }

    public function test_expiry_after_grace_stops_benefits_and_fires_event(): void
    {
        Event::fake([MembershipEnded::class]);
        $user = User::factory()->create();
        $this->payMonth($user);

        $this->travel(35)->days();
        app(MembershipService::class)->sweep();

        $m = $this->membership($user);
        $this->assertSame('expired', $m->status);
        $this->assertNull($m->continuous_since);
        $this->assertFalse($user->fresh()->isActiveMember());
        Event::assertDispatched(MembershipEnded::class);

        // Coming back later starts a new continuous run.
        $this->payMonth($user);
        $this->assertTrue($this->membership($user)->continuous_since->isToday());
    }

    public function test_reminders_before_on_the_day_and_after_are_sent_once(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(9));
        $user = User::factory()->create();
        $this->payMonth($user);
        $end = $this->membership($user)->current_period_end;
        $service = app(MembershipService::class);

        $this->travelTo($end->copy()->subDays(2));
        $this->assertSame(1, $service->sweep()['reminders']);
        $this->assertSame(0, $service->sweep()['reminders']);   // once per kind

        $this->travelTo($end->copy()->addHour());
        $this->assertSame(1, $service->sweep()['reminders']);   // on the day

        $this->travelTo($end->copy()->addDays(1)->addHour());
        $this->assertSame(1, $service->sweep()['reminders']);   // after
        Bus::assertDispatched(SendCustomerMessage::class, fn ($j) => str_contains($j->message->body, 'has ended'));
    }

    public function test_loyalty_reward_after_every_three_continuous_months(): void
    {
        $user = User::factory()->create();
        $this->payMonth($user);
        $service = app(MembershipService::class);

        foreach ([1, 2] as $i) {
            $this->travel(29)->days();
            $this->payMonth($user);
        }
        $this->assertSame(0, $service->grantLoyaltyRewards()); // ~58 days, not yet 3 months
        $this->travel(35)->days();                               // ~93 days, still inside paid period + grace
        $this->assertSame(1, $service->grantLoyaltyRewards());
        $this->assertSame(0, $service->grantLoyaltyRewards());   // never twice
        $this->assertSame(30_000, Wallet::for($user)->available());
    }

    public function test_loyalty_switch_and_amount_are_editable(): void
    {
        $user = User::factory()->member()->create();
        $user->membership->update(['continuous_since' => now()->subMonths(3)->subDay()]);
        Features::set('loyalty_reward', false);
        $this->assertSame(0, app(MembershipService::class)->grantLoyaltyRewards());
        Features::set('loyalty_reward', true);
        Settings::set('loyalty.reward', 50_000);
        $this->assertSame(1, app(MembershipService::class)->grantLoyaltyRewards());
        $this->assertSame(50_000, Wallet::for($user)->available());
    }

    public function test_full_refund_takes_the_month_back(): void
    {
        Event::fake([MembershipEnded::class]);
        $user = User::factory()->create();
        $payment = $this->payMonth($user);
        app(PaymentService::class)->refund($payment, 500_000, 'Changed mind');

        $this->assertSame('expired', $this->membership($user)->status);
        $this->assertFalse($user->fresh()->isActiveMember());
        Event::assertDispatched(MembershipEnded::class);
    }

    public function test_membership_api_pay_with_chapa(): void
    {
        config(['services.chapa.secret_key' => 'CHASECK_TEST-x']);
        Http::fake(['api.chapa.co/*' => Http::response(['data' => ['checkout_url' => 'https://checkout.chapa.co/m']])]);
        $user = User::factory()->create();
        $this->getJson('/api/v1/membership/info')->assertOk()->assertJsonPath('data.price', 500_000);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/membership/pay', ['method' => 'chapa'])
            ->assertCreated()->assertJsonPath('data.checkout_url', 'https://checkout.chapa.co/m')->assertJsonPath('data.amount', 500_000);
        $this->postJson('/api/v1/membership/pay', ['method' => 'chapa'])->assertCreated();
        $this->assertSame(1, MembershipPurchase::query()->count(), 'open purchase reused');
    }

    public function test_membership_switch_off_hides_api(): void
    {
        Features::set('membership', false);
        $this->getJson('/api/v1/membership/info')->assertNotFound();
        $this->assertFalse(User::factory()->member()->create()->isActiveMember());
    }

    public function test_admin_adjust_and_suspend(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();
        $this->postJson("/api/v1/admin/members/{$user->id}/membership", ['days' => 30])->assertUnprocessable();
        $this->postJson("/api/v1/admin/members/{$user->id}/membership", ['days' => 30, 'reason' => 'Gift month'])->assertOk();
        $this->assertTrue($user->fresh()->isActiveMember());
        $this->getJson('/api/v1/admin/members?membership=active')->assertJsonPath('total', 1);

        $this->postJson("/api/v1/admin/members/{$user->id}/status", ['status' => 'suspended', 'reason' => 'Fraud'])->assertOk();
        $this->assertSame('suspended', $user->fresh()->status);
    }
}
