<?php

namespace Tests\Feature\Signals;

use App\Jobs\SendCustomerMessage;
use App\Membership\MembershipService;
use App\Models\Payment;
use App\Models\Signal;
use App\Models\SignalPurchase;
use App\Models\SignalSubscription;
use App\Models\User;
use App\Payments\PaymentService;
use App\Signals\SignalAccess;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SignalsTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNEL = '-1001234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake([SendCustomerMessage::class]);
        config(['services.telegram.bot_token' => 'BOT', 'services.telegram.bot_username' => 'KulmisSignalsBot',
            'services.telegram.channel_id' => self::CHANNEL, 'services.telegram.webhook_secret' => 'tg-secret']);
        Http::fake(['api.telegram.org/*' => function ($request) {
            $method = basename($request->url());

            return Http::response(['ok' => true, 'result' => match ($method) {
                'createChatInviteLink' => ['invite_link' => 'https://t.me/+personal123'],
                'sendMessage' => ['message_id' => 77],
                default => true,
            }]);
        }]);
    }

    private function guestPays(string $phone = '0944556677'): SignalSubscription
    {
        $purchase = app(SignalAccess::class)->newGuestPurchase(['first_name' => 'Liya', 'phone' => $phone]);
        $this->assertSame(400_000, $purchase->amount);
        $payment = app(PaymentService::class)->submitProof($purchase, Payment::TELEBIRR, UploadedFile::fake()->image('p.jpg'), 'x', null);
        app(PaymentService::class)->approveProof($payment, User::factory()->superAdmin()->create()->id);

        return $purchase->subscription()->firstOrFail();
    }

    private function tg(array $update): TestResponse
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'tg-secret'])->postJson('/api/v1/webhooks/telegram', $update);
    }

    private function start(SignalSubscription $sub, int $tgUser): void
    {
        $this->tg(['message' => ['text' => '/start '.$sub->link_token, 'from' => ['id' => $tgUser], 'chat' => ['id' => $tgUser, 'type' => 'private']]])->assertOk();
    }

    private function joinRequest(int $tgUser): void
    {
        $this->tg(['chat_join_request' => ['chat' => ['id' => (int) self::CHANNEL], 'from' => ['id' => $tgUser]]])->assertOk();
    }

    public function test_guest_purchase_gives_a_personal_link_by_message(): void
    {
        $sub = $this->guestPays();
        $this->assertTrue($sub->isEntitled());
        Bus::assertDispatched(SendCustomerMessage::class, fn ($j) => str_contains($j->message->body, 'https://t.me/KulmisSignalsBot?start='.$sub->link_token));
    }

    public function test_start_binds_account_and_join_request_is_approved_only_for_that_account(): void
    {
        $sub = $this->guestPays();
        $this->start($sub, 1001);
        $this->assertSame(1001, $sub->fresh()->telegram_user_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'createChatInviteLink') && $r['creates_join_request'] === true);

        $this->joinRequest(1001);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'approveChatJoinRequest') && $r['user_id'] === 1001);
        $this->assertNotNull($sub->fresh()->joined_at);

        // A forwarded link: someone else's join request is declined.
        $this->joinRequest(2002);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'declineChatJoinRequest') && $r['user_id'] === 2002);
    }

    public function test_link_cannot_be_claimed_by_a_second_telegram_account(): void
    {
        $sub = $this->guestPays();
        $this->start($sub, 1001);
        $this->start($sub, 3003);
        $this->assertSame(1001, $sub->fresh()->telegram_user_id);
    }

    public function test_webhook_requires_secret(): void
    {
        $this->postJson('/api/v1/webhooks/telegram', ['message' => []])->assertForbidden();
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'nope'])->postJson('/api/v1/webhooks/telegram', [])->assertForbidden();
    }

    public function test_removed_automatically_after_access_and_grace_end(): void
    {
        $sub = $this->guestPays();
        $this->start($sub, 1001);
        $this->joinRequest(1001);

        $this->travel(32)->days();                // in grace
        $this->assertSame(0, app(SignalAccess::class)->sweep());
        $this->travel(2)->days();                 // grace over
        $this->assertSame(1, app(SignalAccess::class)->sweep());

        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'banChatMember') && $r['user_id'] === 1001);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'unbanChatMember'));
        $this->assertSame('ended', $sub->fresh()->status);
        $this->assertSame(0, app(SignalAccess::class)->sweep(), 'not removed twice');
    }

    public function test_renewing_extends_and_allows_rejoin(): void
    {
        $sub = $this->guestPays();
        $end = $sub->current_period_end;
        $this->guestPays();
        $this->assertTrue($sub->fresh()->current_period_end->eq($end->copy()->addMonthNoOverflow()));
    }

    public function test_members_get_signals_free_and_lose_them_when_membership_ends(): void
    {
        $user = User::factory()->create();
        $purchase = app(MembershipService::class)->newPurchase($user);
        $pay = app(PaymentService::class)->submitProof($purchase, Payment::BANK, UploadedFile::fake()->image('x.jpg'), null, null);
        app(PaymentService::class)->approveProof($pay, User::factory()->superAdmin()->create()->id);

        $sub = SignalSubscription::query()->where('user_id', $user->id)->where('source', 'membership')->firstOrFail();
        $this->assertTrue($sub->isEntitled());
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/signals/member-access')->assertOk()
            ->assertJsonPath('data.telegram_link', 'https://t.me/KulmisSignalsBot?start='.$sub->link_token);

        $this->start($sub, 5005);
        $this->joinRequest(5005);
        $this->assertSame(5005, $user->fresh()->telegram_user_id);

        $this->travel(35)->days();
        app(MembershipService::class)->sweep();   // expires → MembershipEnded → removed
        $this->assertSame('ended', $sub->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'banChatMember') && $r['user_id'] === 5005);
    }

    public function test_active_members_cannot_buy_signals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(SignalAccess::class)->newGuestPurchase(['first_name' => 'A', 'phone' => '0911000000'], User::factory()->member()->create());
    }

    public function test_refund_removes_access(): void
    {
        $sub = $this->guestPays();
        $this->start($sub, 1001);
        $payment = Payment::query()->where('payable_type', (new SignalPurchase)->getMorphClass())->firstOrFail();
        app(PaymentService::class)->refund($payment, 400_000, 'Requested');
        $this->assertSame('ended', $sub->fresh()->status);
    }

    public function test_admin_posts_signal_to_channel_and_result_locks_it(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/v1/admin/signals', ['pair' => 'BTC/USDT', 'direction' => 'long', 'entry' => '64,200 – 64,500',
            'targets' => ['65,000', '66,200'], 'stop_loss' => '63,400', 'notes' => 'Risk 1%'])
            ->assertCreated()->assertJsonPath('data.telegram_message_id', 77)->json('data.id');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'sendMessage') && $r['chat_id'] === self::CHANNEL
            && str_contains($r['text'], 'Target 2: 66,200') && str_contains($r['text'], 'Stop loss: 63,400'));

        $this->putJson("/api/v1/admin/signals/$id", ['pair' => 'BTC/USDT', 'direction' => 'long', 'entry' => '64,300',
            'targets' => ['65,000'], 'stop_loss' => '63,400'])->assertOk();
        $this->postJson("/api/v1/admin/signals/$id/close", ['result' => 'win', 'result_value' => '+2.1%'])->assertOk();
        $this->putJson("/api/v1/admin/signals/$id", ['pair' => 'X', 'direction' => 'long', 'entry' => '1', 'targets' => ['2'], 'stop_loss' => '0'])
            ->assertUnprocessable();
        $this->postJson("/api/v1/admin/signals/$id/close", ['result' => 'loss'])->assertUnprocessable();

        $this->expectException(\LogicException::class);
        Signal::query()->findOrFail($id)->update(['result' => 'loss']);
    }

    public function test_public_performance_page_and_switch(): void
    {
        Signal::query()->create(['pair' => 'EUR/USD', 'direction' => 'short', 'entry' => '1.08', 'targets' => ['1.07'], 'stop_loss' => '1.09',
            'status' => 'closed', 'result' => 'win', 'closed_at' => now(), 'locked_at' => now()]);
        Signal::query()->create(['pair' => 'XAU/USD', 'direction' => 'long', 'entry' => '2400', 'targets' => ['2450'], 'stop_loss' => '2380',
            'status' => 'closed', 'result' => 'loss', 'closed_at' => now(), 'locked_at' => now()]);
        Signal::query()->create(['pair' => 'OPEN', 'direction' => 'long', 'entry' => '1', 'targets' => ['2'], 'stop_loss' => '0']);

        $this->getJson('/api/v1/signals/performance')->assertOk()->assertJsonPath('data.totals.win_rate', 50)
            ->assertJsonPath('data.totals.closed', 2)->assertJsonCount(2, 'data.recent');
        Features::set('signals.performance_page', false);
        $this->getJson('/api/v1/signals/performance')->assertNotFound();
    }

    public function test_admin_manual_add_and_remove(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/v1/admin/signals/subscribers', ['first_name' => 'VIP', 'phone' => '0911223344', 'days' => 7, 'reason' => 'Partner'])
            ->assertCreated()->json('data.id');
        $sub = SignalSubscription::query()->findOrFail($id);
        $this->assertTrue($sub->isEntitled());
        $this->start($sub, 9009);

        $this->postJson("/api/v1/admin/signals/subscribers/$id/remove", ['reason' => 'Ended deal'])->assertOk();
        $this->assertSame('ended', $sub->fresh()->status);
        $this->assertFalse($sub->fresh()->isEntitled());
    }

    public function test_guest_pays_by_transfer_and_info(): void
    {
        Storage::fake('local');
        $this->getJson('/api/v1/signals/info')->assertOk()->assertJsonPath('data.guest_price', 400_000);
        $this->post('/api/v1/signals/subscribe', ['first_name' => 'Liya', 'phone' => '0944556677'])->assertNotFound();
        $this->postJson('/api/v1/signals/payment-proof', ['first_name' => 'Liya', 'phone' => '0944556677', 'method' => 'telebirr',
            'reference' => 'TB55', 'proof' => UploadedFile::fake()->image('r.jpg')])
            ->assertCreated()->assertJsonPath('data.status', 'awaiting_review');
        $this->assertSame('awaiting_review', Payment::query()->latest('id')->firstOrFail()->status);
        $this->get('/signals')->assertOk()->assertSee('Send receipt')->assertDontSee('Chapa');
    }

    public function test_signals_switch_off(): void
    {
        Features::set('signals', false);
        $this->getJson('/api/v1/signals/info')->assertNotFound();
        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/signals')->assertOk(); // its history stays in the admin
    }
}
