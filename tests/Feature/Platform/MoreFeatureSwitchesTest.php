<?php

namespace Tests\Feature\Platform;

use App\Jobs\SendCustomerMessage;
use App\Models\Badge;
use App\Models\FeatureFlag;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Notify\Message;
use App\Notify\OrderNotifier;
use App\Notify\Sms\SmsGateway;
use App\Support\Features;
use App\Support\Settings;
use App\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/** The switches added in round 4: every one has an easy description and really hides its feature. */
class MoreFeatureSwitchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_switch_has_a_section_and_an_easy_description(): void
    {
        foreach (Features::overview() as $row) {
            $this->assertNotSame('', trim($row['description']), $row['key'].' has no description');
            $this->assertNotSame('', trim($row['group']), $row['key']);
            $this->assertLessThanOrEqual(140, mb_strlen($row['description']), $row['key'].': keep it short');
            if ($row['parent'] !== null) {
                $this->assertTrue(Features::exists($row['parent']), $row['key'].' has an unknown parent');
            }
        }
    }

    public function test_the_brief_list_is_covered(): void
    {
        foreach (['shop.guest_checkout', 'shop.coupons', 'shop.early_access', 'shop.members_only_product', 'shop.free_shipping',
            'shop.order_tracking', 'payments.pdf_receipts', 'payments.manual', 'wallet.spend', 'wallet.cashout', 'loyalty_reward',
            'top_referrers', 'signals.performance_page', 'site.offline_page', 'site.instant_loading', 'appearance.theme_switch',
            'referral.share_buttons', 'referral.qr_codes', 'accounts.signup', 'accounts.two_factor', 'notifications.sms',
            'notifications.email', 'notifications.telegram', 'badges'] as $key) {
            $this->assertTrue(Features::exists($key), $key);
        }
        foreach (array_keys(config('settings')) as $setting) {
            // Each customer message type has its own switch (account-safety messages are always sent).
            if (str_starts_with($setting, 'notify.') && ! str_starts_with($setting, 'notify.security_') && $setting !== 'notify.badge_unlocked') {
                $this->assertTrue(Features::exists('messages.'.substr($setting, 7)), $setting.' has no switch');
            }
        }
    }

    public function test_each_badge_has_its_own_switch_backed_by_the_badge(): void
    {
        $bronze = Badge::query()->where('key', 'bronze')->firstOrFail();
        $row = collect(Features::overview())->firstWhere('key', 'badge.bronze');
        $this->assertNotNull($row);
        $this->assertSame('Badges', $row['group']);
        $this->assertTrue($row['effective']);

        Features::set('badge.bronze', false);
        $this->assertFalse($bronze->fresh()->is_active);
        $this->assertFalse(Features::enabled('badge.bronze'));

        Features::set('badge.bronze', true);
        Features::set('badges', false);
        $this->assertTrue(Features::own('badge.bronze'));
        $this->assertFalse(Features::enabled('badge.bronze'), 'all badges off turns each badge off');
        $this->assertFalse(Features::exists('badge.nope'));
    }

    public function test_admin_can_switch_a_badge_and_unknown_badges_are_refused(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin)->putJson('/api/v1/admin/features', ['features' => ['badge.gold' => false]])->assertOk()
            ->assertJsonFragment(['key' => 'badge.gold', 'enabled' => false]);
        $this->assertFalse(Badge::query()->where('key', 'gold')->value('is_active'));
        $this->actingAs($admin)->putJson('/api/v1/admin/features', ['features' => ['badge.nope' => false]])->assertUnprocessable();
    }

    public function test_a_switched_off_message_type_is_not_sent_but_safety_messages_are(): void
    {
        Mail::fake();
        $sms = Mockery::mock(SmsGateway::class);
        $this->app->instance(SmsGateway::class, $sms);

        Features::set('messages.payout_paid', false);
        $sms->shouldNotReceive('send');
        (new SendCustomerMessage(new Message('Your cash-out', 'sent', '+251911223344', 'a@example.com', null, 'payout_paid')))
            ->handle($sms, app(TelegramClient::class));
        Mail::assertNothingSent();

        $sms2 = Mockery::mock(SmsGateway::class);
        $sms2->shouldReceive('send')->once();
        (new SendCustomerMessage(new Message('Security notice', 'x', '+251911223344', null, null, 'payout_paid'), null, true))
            ->handle($sms2, app(TelegramClient::class));

        Features::set('messages.payout_paid', true);
        $this->assertTrue((new Message('s', 'b', type: 'payout_paid'))->allowed());
        $this->assertTrue((new Message('s', 'b'))->allowed(), 'untyped messages (password reset) are always allowed');
    }

    public function test_message_type_follows_its_feature(): void
    {
        Features::set('wallet', false);
        $this->assertFalse((new Message('s', 'b', type: 'payout_paid'))->allowed(), 'cash-out messages go with the wallet');
    }

    public function test_order_tracking_off_hides_links_and_points_messages_elsewhere(): void
    {
        $this->get('/')->assertSee('Track order');
        Features::set('shop.order_tracking', false);
        $this->get('/')->assertDontSee('Track order')->assertDontSee('Track your order');

        $order = new Order(['number' => 'KM100', 'user_id' => null]);
        $this->assertSame(url('/'), OrderNotifier::trackUrl($order));
        $order->user_id = 5;
        $this->assertSame(url('/account/orders'), OrderNotifier::trackUrl($order));
        Features::set('shop.order_tracking', true);
        $this->assertSame(url('/track?number=KM100'), OrderNotifier::trackUrl($order));
    }

    public function test_sign_up_off_hides_create_account_links(): void
    {
        $this->get('/login')->assertSee('Create an account');
        Features::set('accounts.signup', false);
        $this->get('/login')->assertOk()->assertDontSee('Create an account');
        $this->get('/membership')->assertDontSee(url('/register'));
    }

    public function test_two_factor_off_hides_set_up_but_people_who_use_it_keep_it(): void
    {
        $user = User::factory()->create();
        Features::set('accounts.two_factor', false);
        $this->actingAs($user)->get('/account/security')->assertOk()->assertDontSee('Set up two-step sign-in');

        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($user)->get('/account/security')->assertSee('Two-step sign-in')->assertSee('Turn off');
        $this->actingAs($user)->post('/account/security/two-factor/disable', [])->assertStatus(302); // still reachable (validation)
    }

    public function test_share_buttons_and_qr_codes_can_be_hidden_in_the_member_area_and_app(): void
    {
        $member = User::factory()->member()->create();
        $page = $this->actingAs($member)->get('/account')->assertOk();
        $page->assertSee('WhatsApp')->assertSee('referral-qr.svg');

        Features::set('referral.share_buttons', false);
        Features::set('referral.qr_codes', false);
        $this->actingAs($member)->get('/account')->assertOk()->assertDontSee('WhatsApp')->assertDontSee('referral-qr.svg');
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/referral')->assertOk()
            ->assertJsonPath('data.share', null)->assertJsonPath('data.qr_url', null);
    }

    public function test_instant_loading_and_offline_page_switches_reach_the_page(): void
    {
        $this->get('/')->assertSee('speculationrules', false)->assertDontSee('data-instant="off"', false);
        Features::set('site.instant_loading', false);
        Features::set('site.offline_page', false);
        $this->get('/')->assertDontSee('type="speculationrules"', false)
            ->assertSee('data-instant="off"', false)->assertSee('data-offline="off"', false);
    }

    public function test_queue_workers_see_switch_changes_made_after_they_started(): void
    {
        $this->assertTrue(Features::enabled('signals'));
        $this->assertSame(500_000, Settings::int('membership.price'));
        // Another process (the admin) changes them: the shared cache is cleared, this process still has its memo.
        FeatureFlag::query()->create(['key' => 'signals', 'enabled' => false]);
        Setting::query()->updateOrCreate(['key' => 'membership.price'], ['value' => 600_000]);
        Cache::flush();
        $this->assertTrue(Features::enabled('signals'), 'memo still old before the next job');

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        $job->shouldIgnoreMissing();
        event(new JobProcessing('database', $job));
        $this->assertFalse(Features::enabled('signals'));
        $this->assertSame(600_000, Settings::int('membership.price'));
    }
}
