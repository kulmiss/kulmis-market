<?php

namespace Tests\Feature\Platform;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $twoFactor = true): User
    {
        $user = User::factory()->superAdmin()->create(['email' => 'boss@example.com', 'password' => 'correct-horse-battery']);
        if ($twoFactor) {
            $user->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        }

        return $user;
    }

    public function test_customer_cannot_log_in_to_admin(): void
    {
        User::factory()->create(['email' => 'c@example.com', 'password' => 'secret-secret']);
        $this->postJson('/api/v1/admin/auth/login', ['login' => 'c@example.com', 'password' => 'secret-secret'])->assertUnprocessable();
    }

    public function test_login_requires_totp_when_enabled_and_refuses_replay(): void
    {
        $user = $this->admin();
        $this->postJson('/api/v1/admin/auth/login', ['login' => 'boss@example.com', 'password' => 'correct-horse-battery'])
            ->assertOk()->assertJson(['two_factor' => true]);
        $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();

        $this->postJson('/api/v1/admin/auth/two-factor', ['code' => '000000'])->assertUnprocessable();
        $code = Totp::code((string) $user->two_factor_secret, intdiv(time(), 30));
        $this->postJson('/api/v1/admin/auth/two-factor', ['code' => $code])->assertOk();
        $this->getJson('/api/v1/admin/features')->assertOk();

        // Same code cannot be used again.
        $this->postJson('/api/v1/admin/auth/logout')->assertOk();
        $this->postJson('/api/v1/admin/auth/login', ['login' => 'boss@example.com', 'password' => 'correct-horse-battery'])->assertOk();
        $this->postJson('/api/v1/admin/auth/two-factor', ['code' => $code])->assertUnprocessable();
    }

    public function test_recovery_code_works_once(): void
    {
        $user = $this->admin();
        $user->forceFill(['two_factor_recovery_codes' => ['AAAAA-BBBBB', 'CCCCC-DDDDD']])->save();

        $this->postJson('/api/v1/admin/auth/login', ['login' => 'boss@example.com', 'password' => 'correct-horse-battery']);
        $this->postJson('/api/v1/admin/auth/two-factor', ['recovery_code' => 'aaaaa-bbbbb'])->assertOk();
        $this->assertSame(['CCCCC-DDDDD'], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_admin_without_2fa_must_set_it_up_first(): void
    {
        $user = $this->admin(false);
        $this->postJson('/api/v1/admin/auth/login', ['login' => 'boss@example.com', 'password' => 'correct-horse-battery'])
            ->assertOk()->assertJson(['two_factor_setup_required' => true]);

        $this->getJson('/api/v1/admin/features')->assertForbidden()->assertJson(['two_factor_setup_required' => true]);

        $setup = $this->postJson('/api/v1/admin/two-factor/setup')->assertOk();
        $secret = $setup->json('secret');
        $this->assertNotEmpty($secret);
        // Raw SVG markup (the admin inlines it), not a data: URI.
        $this->assertStringContainsString('<svg', $setup->json('qr_svg'));
        $this->assertStringNotContainsString('data:image', $setup->json('qr_svg'));
        $this->postJson('/api/v1/admin/two-factor/confirm', ['code' => '123456'])->assertUnprocessable();
        $codes = $this->postJson('/api/v1/admin/two-factor/confirm', ['code' => Totp::code($secret, intdiv(time(), 30))])
            ->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);

        $this->getJson('/api/v1/admin/features')->assertOk();
        // Secret is stored encrypted.
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
    }

    public function test_staff_abilities_are_enforced(): void
    {
        $this->actingAsAdmin('fulfilment');
        $this->getJson('/api/v1/admin/settings')->assertForbidden();

        $this->actingAsAdmin('finance');
        $this->getJson('/api/v1/admin/settings')->assertForbidden();
    }

    public function test_guest_request_never_reaches_admin(): void
    {
        $this->getJson('/api/v1/admin/features')->assertUnauthorized();
    }

    public function test_activity_log_is_append_only(): void
    {
        $this->admin();
        $this->postJson('/api/v1/admin/auth/login', ['login' => 'boss@example.com', 'password' => 'nope']);
        $log = ActivityLog::query()->create(['action' => 'x']);

        $this->expectException(\LogicException::class);
        $log->update(['action' => 'y']);
    }

    public function test_create_admin_command(): void
    {
        $this->artisan('km:create-admin', ['email' => 'me@example.com'])
            ->expectsQuestion('Password (min 5 characters)', 'abcde')
            ->assertSuccessful();
        $this->assertTrue(User::query()->where('email', 'me@example.com')->firstOrFail()->isSuperAdmin());
    }

    public function test_create_admin_rejects_short_password_and_min_is_configurable(): void
    {
        $this->artisan('km:create-admin', ['email' => 'me@example.com'])
            ->expectsQuestion('Password (min 5 characters)', 'abcd')
            ->assertFailed();

        config(['shop.admin_password_min' => 12]);
        $this->artisan('km:create-admin', ['email' => 'me@example.com'])
            ->expectsQuestion('Password (min 12 characters)', 'abcde')
            ->assertFailed();
        $this->assertFalse(User::query()->where('email', 'me@example.com')->exists());
    }
}
