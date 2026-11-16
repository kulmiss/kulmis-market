<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "I lost my 2FA phone and my recovery codes": km:reset-2fa on the server. */
class ResetTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_resets_two_step_sign_in_for_one_account(): void
    {
        $admin = User::factory()->role('super_admin')->create(['email' => 'owner@example.com']);
        $admin->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['a', 'b']])->save();
        $other = User::factory()->role('finance')->create();
        $other->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();

        $this->artisan('km:reset-2fa', ['login' => 'OWNER@example.com', '--force' => true])->assertSuccessful();
        $this->assertFalse($admin->fresh()->hasTwoFactor());
        $this->assertNull($admin->fresh()->two_factor_recovery_codes);
        $this->assertTrue($other->fresh()->hasTwoFactor());
        $this->assertDatabaseHas('activity_log', ['action' => 'security.two_factor_reset_on_server']);

        $this->artisan('km:reset-2fa', ['login' => 'nobody@example.com', '--force' => true])->assertFailed();
    }
}
