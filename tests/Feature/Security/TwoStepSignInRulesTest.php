<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two-step sign-in rules the owner asked to keep:
 * - Super Admin and every staff role MUST use it (no admin screen without it).
 * - Customers: the switch is ON and it is optional (members choose).
 */
class TwoStepSignInRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_staff_role_must_set_up_two_step_sign_in_before_any_admin_screen(): void
    {
        $this->assertTrue((bool) config('permissions.require_2fa'));
        foreach (array_keys(config('permissions.roles')) as $role) {
            if ($role === 'customer') {
                continue;
            }
            $this->app['auth']->forgetGuards();
            $this->flushSession();
            User::factory()->create(['email' => "$role@example.com", 'password' => 'correct-horse-battery', 'role' => $role]);
            $this->postJson('/api/v1/admin/auth/login', ['login' => "$role@example.com", 'password' => 'correct-horse-battery'])
                ->assertOk()->assertJson(['two_factor_setup_required' => true]);
            foreach (['/api/v1/admin/features', '/api/v1/admin/orders', '/api/v1/admin/dashboard', '/api/v1/admin/members'] as $uri) {
                $this->getJson($uri)->assertForbidden()->assertJson(['two_factor_setup_required' => true]);
            }
        }
    }

    public function test_a_live_shop_always_requires_it_even_if_the_env_says_no(): void
    {
        $config = fn (string $env, string $flag) => (function () use ($env, $flag) {
            $old = [$_ENV['APP_ENV'] ?? null, $_ENV['ADMIN_REQUIRE_2FA'] ?? null];
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;
            $_ENV['ADMIN_REQUIRE_2FA'] = $_SERVER['ADMIN_REQUIRE_2FA'] = $flag;
            putenv("APP_ENV=$env");
            putenv("ADMIN_REQUIRE_2FA=$flag");
            $value = (require base_path('config/permissions.php'))['require_2fa'];
            foreach (['APP_ENV' => $old[0] ?? 'testing', 'ADMIN_REQUIRE_2FA' => $old[1] ?? 'true'] as $k => $v) {
                $_ENV[$k] = $_SERVER[$k] = $v;
                putenv("$k=$v");
            }

            return $value;
        })();
        $this->assertTrue($config('production', 'false'), 'live shop: always on');
        $this->assertFalse($config('local', 'false'), "a developer's computer may turn it off");
        $this->assertTrue($config('local', 'true'));
    }

    public function test_customers_two_step_switch_is_on_and_optional(): void
    {
        $this->assertTrue((bool) config('features')['accounts.two_factor'][2], 'on by default');
        $this->assertTrue(Features::enabled('accounts.two_factor'));
        // Optional: a member without it signs in with the password and can turn it on.
        $u = User::factory()->member()->create(['email' => 'sara@example.com', 'password' => 'buna-time-2026x']);
        $this->post('/login', ['login' => 'sara@example.com', 'password' => 'buna-time-2026x'])->assertRedirect('/account');
        $this->assertAuthenticatedAs($u, 'web');
        $this->get('/account/security')->assertOk()->assertSee('Two-step sign-in');
    }
}
