<?php

namespace Tests;

use App\Models\User;
use App\Support\Features;
use App\Support\Settings;
use App\Support\Totp;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Features::flush();
        Settings::flush();
    }

    /** A Super Admin (or other role) with 2FA confirmed, signed in. */
    protected function actingAsAdmin(string $role = 'super_admin'): User
    {
        $user = User::factory()->role($role)->create();
        $user->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($user, 'web');

        return $user;
    }
}
