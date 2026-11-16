<?php

namespace Tests\Feature\Platform;

use App\Badges\ProfitGuard;
use App\Mail\PlainMessage;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The Super Admin's first-time setup wizard and the "still to do" list. */
class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_sign_in_of_the_super_admin_shows_the_wizard_and_staff_never_see_it(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('setup_needed', true);
        $this->getJson('/api/v1/admin/setup')->assertOk()->assertJsonPath('data.step', 1)->assertJsonPath('data.finished', false)
            ->assertJsonPath('data.security.two_factor', true)
            ->assertJsonPath('data.services.0.key', 'payments');
        $this->assertSame(500_000, $this->getJson('/api/v1/admin/setup')->json('data.values')['membership.price']);

        foreach (['manager', 'finance', 'fulfilment', 'support'] as $role) {
            $this->actingAsAdmin($role);
            $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('setup_needed', false);
            $this->getJson('/api/v1/admin/setup')->assertForbidden();
            $this->putJson('/api/v1/admin/setup', ['finished' => true])->assertForbidden();
        }
        $this->assertFalse(Settings::get('setup.finished'));
    }

    public function test_switched_off_the_wizard_never_shows(): void
    {
        $this->actingAsAdmin();
        Features::set('admin.setup_wizard', false);
        $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('setup_needed', false);
        $this->getJson('/api/v1/admin/setup')->assertJsonPath('data.on', false);
    }

    public function test_progress_is_saved_skip_and_finish_work_and_it_can_be_opened_again(): void
    {
        $this->actingAsAdmin();
        $this->putJson('/api/v1/admin/setup', ['step' => 3])->assertJsonPath('data.step', 3);
        $this->getJson('/api/v1/admin/setup')->assertJsonPath('data.step', 3); // back where you left
        $this->putJson('/api/v1/admin/setup', ['step' => 6])->assertUnprocessable();

        $this->putJson('/api/v1/admin/setup', ['finished' => true])->assertJsonPath('data.finished', true);
        $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('setup_needed', false);

        $this->putJson('/api/v1/admin/setup', ['restart' => true])->assertJsonPath('data.finished', false)->assertJsonPath('data.step', 1);
        $this->getJson('/api/v1/admin/auth/me')->assertJsonPath('setup_needed', true);
    }

    public function test_finish_saves_everything_through_the_normal_settings(): void
    {
        $this->actingAsAdmin();
        $this->putJson('/api/v1/admin/settings', ['settings' => ['site.name' => 'Selam Crafts', 'appearance.shop_color' => '#7a3cc2',
            'site.support_email' => 'hi@selam.test', 'site.whatsapp' => '+251911000000']])->assertOk();
        $this->putJson('/api/v1/admin/settings', ['settings' => ['membership.price' => 500_000, 'wallet.cashout_min' => 100_000]])->assertOk();
        $this->putJson('/api/v1/admin/features', ['features' => ['shop.gift_cards' => false]])->assertOk();
        $this->putJson('/api/v1/admin/setup', ['finished' => true])->assertOk();

        $this->get('/')->assertOk()->assertSee('Selam Crafts')
            ->assertSee('--color-brand-600: #7a3cc2', false);
        $this->assertSame('#7a3cc2', Settings::string('appearance.shop_color'));
    }

    public function test_unsafe_prices_are_refused_and_the_preview_never_saves(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/admin/setup/preview', ['settings' => ['referral.rate.membership.l1' => 1_000]])
            ->assertOk()->assertJsonPath('data.safe', true)->assertJsonPath('data.keep', ProfitGuard::worstCase()['keep']);
        $cheaper = $this->postJson('/api/v1/admin/setup/preview', ['settings' => ['membership.price' => 400_000]])->json('data.keep');
        $this->assertLessThan(ProfitGuard::worstCase()['keep'], $cheaper);
        $this->postJson('/api/v1/admin/setup/preview', ['settings' => ['referral.rate.membership.l1' => 4_000]])
            ->assertOk()->assertJsonPath('data.safe', false);
        $this->postJson('/api/v1/admin/setup/preview', ['settings' => ['site.name' => 'x']])->assertUnprocessable();
        $this->assertSame(1_000, Settings::int('referral.rate.membership.l1')); // nothing saved

        $this->putJson('/api/v1/admin/settings', ['settings' => ['referral.rate.membership.l1' => 4_000]])->assertUnprocessable();
        $this->assertSame(1_000, Settings::int('referral.rate.membership.l1'));
    }

    public function test_internal_keys_are_not_listed_or_editable_in_settings(): void
    {
        $this->actingAsAdmin();
        $keys = collect($this->getJson('/api/v1/admin/settings')->json('data'))->pluck('key');
        $this->assertNotContains('setup.finished', $keys);
        $this->assertNotContains('appearance.logo', $keys);
        $this->assertContains('appearance.shop_color', $keys);
        $this->putJson('/api/v1/admin/settings', ['settings' => ['setup.finished' => true]])->assertUnprocessable();
        $this->putJson('/api/v1/admin/settings', ['settings' => ['appearance.logo' => '../../.env']])->assertUnprocessable();
    }

    public function test_logo_upload_shows_in_the_shop_and_can_be_removed(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $this->post('/api/v1/admin/setup/logo', ['logo' => UploadedFile::fake()->createWithContent('x.svg', '<svg onload=alert(1)>')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $url = $this->post('/api/v1/admin/setup/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 300)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.logo_url');
        $this->assertNotNull($url);
        $path = Settings::string('appearance.logo');
        $this->assertMatchesRegularExpression('#^branding/logo-[a-z0-9]{12}\.png$#', $path);
        Storage::disk('public')->assertExists($path);
        $this->get('/')->assertSee('data-shop-logo', false);

        $this->deleteJson('/api/v1/admin/setup/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        Storage::disk('public')->assertMissing($path);
        $this->get('/')->assertDontSee('data-shop-logo', false);
    }

    public function test_services_show_their_status_and_tests_never_show_secrets(): void
    {
        config(['services.telegram.bot_token' => '123:SECRET-TOKEN', 'mail.default' => 'smtp']);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true, 'result' => ['username' => 'kulmis_bot']])
            ->push(['ok' => false, 'description' => 'Unauthorized 123:SECRET-TOKEN'], 401)]);
        Mail::fake();
        $admin = $this->actingAsAdmin();
        $admin->forceFill(['email' => 'owner@example.com'])->save();

        $state = $this->getJson('/api/v1/admin/setup')->assertOk();
        $this->assertStringNotContainsString('SECRET-TOKEN', $state->getContent());
        $this->assertTrue(collect($state->json('data.services'))->firstWhere('key', 'telegram')['connected']);
        $this->assertFalse(collect($state->json('data.services'))->firstWhere('key', 'sms')['connected']);

        $this->postJson('/api/v1/admin/setup/test/telegram')->assertJsonPath('data.ok', true)->assertJsonPath('data.message', 'The bot @kulmis_bot answers.');
        $this->postJson('/api/v1/admin/setup/test/email')->assertJsonPath('data.ok', true);
        Mail::assertSent(PlainMessage::class, fn ($m) => $m->hasTo('owner@example.com'));
        $this->postJson('/api/v1/admin/setup/test/chapa')->assertNotFound();

        $bad = $this->postJson('/api/v1/admin/setup/test/telegram')->assertJsonPath('data.ok', false);
        $this->assertStringNotContainsString('SECRET', $bad->getContent());
    }

    public function test_checklist_shows_what_is_still_not_done(): void
    {
        $this->actingAsAdmin();
        $list = collect($this->getJson('/api/v1/admin/setup')->json('data.checklist'))->keyBy('key');
        $this->assertTrue($list['two_factor']['done']);
        $this->assertFalse($list['payments']['done']); // still the example account details
        $this->assertTrue($list['demo']['done']);

        $this->putJson('/api/v1/admin/settings', ['settings' => ['payments.bank_details' => 'CBE 1000123', 'payments.telebirr_details' => 'Telebirr 0911']])->assertOk();
        $list = collect($this->getJson('/api/v1/admin/setup')->json('data.checklist'))->keyBy('key');
        $this->assertTrue($list['payments']['done']);
    }
}
