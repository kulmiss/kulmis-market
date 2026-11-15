<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ThrottlePerRoute;
use App\Jobs\SendCustomerMessage;
use App\Models\User;
use App\Security\EmailChange;
use App\Security\PasswordPolicy;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PASS = 'quiet river at dawn 7';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendCustomerMessage::class]);
        // The per-IP throttles are tested elsewhere; here we test the per-account pause.
        $this->withoutMiddleware([ThrottleRequests::class, ThrottlePerRoute::class]);
    }

    private function customer(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['phone' => '+251911223344', 'email' => 'sara@example.com', 'first_name' => 'Sara', 'password' => self::PASS], $attrs));
    }

    /** @param list<string> $events */
    private function assertAlerted(string $needle, int $times = 1): void
    {
        $count = Bus::dispatched(SendCustomerMessage::class)
            ->filter(fn (SendCustomerMessage $job) => $job->security && str_contains($job->message->body, $needle))->count();
        $this->assertSame($times, $count, "Expected $times security message(s) containing \"$needle\", got $count.");
    }

    // ── Passwords ─────────────────────────────────────────────

    public function test_password_rules_refuse_short_common_and_personal_passwords(): void
    {
        $person = ['phone' => '+251911223344', 'email' => 'sara.k@example.com', 'first_name' => 'Sara', 'last_name' => 'Kebede'];
        $check = fn (string $p, bool $staff = false) => Validator::make(['password' => $p], ['password' => PasswordPolicy::rules($staff, $person)])->passes();

        foreach (['short1', 'password123', 'Password2024!', 'iloveyou12', 'qwertyuiop', 'ethiopia123', '0911223344xx', 'sara.k-2024', 'Sara2024', 'kebede1234'] as $bad) {
            $this->assertFalse($check($bad), "\"$bad\" should be refused");
        }
        foreach (['quiet river at dawn', 'my mesob basket 42', 'buna-ceremony-sunday'] as $good) {
            $this->assertTrue($check($good), "\"$good\" should be accepted");
        }
        $this->assertFalse($check('ten-chars!'.'', true), 'staff need 12');
        $this->assertTrue($check('blue camel sings', true));
        $this->assertFalse($check(str_repeat('a', 129).'Z9'), 'over the maximum');
    }

    public function test_sign_up_and_password_change_use_the_rules(): void
    {
        $this->post('/register', ['first_name' => 'Abel', 'last_name' => 'T', 'phone' => '0911000111', 'password' => 'password123',
            'password_confirmation' => 'password123', 'terms' => '1'])->assertSessionHasErrors('password');
        $this->postJson('/api/v1/auth/register', ['first_name' => 'Abel', 'last_name' => 'T', 'phone' => '0911000111', 'password' => '123456789012'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/register', ['first_name' => 'Abel', 'last_name' => 'T', 'phone' => '0911000111', 'password' => self::PASS])->assertCreated();
    }

    // ── Sign-in pause ─────────────────────────────────────────

    public function test_ten_wrong_passwords_pause_the_account_on_every_channel(): void
    {
        $u = $this->customer();
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/v1/auth/login', ['login' => '0911223344', 'password' => 'wrong-'.$i])->assertUnprocessable();
        }
        $this->assertNotNull($u->fresh()->locked_until);
        $this->assertAlerted('too many wrong passwords');

        // Even the right password is refused now — on the API and the website.
        $this->postJson('/api/v1/auth/login', ['login' => '0911223344', 'password' => self::PASS])
            ->assertUnprocessable()->assertJsonFragment(['login' => ['Too many wrong passwords. For your safety, sign-in is paused for 15 more minutes.']]);
        $this->post('/login', ['login' => 'sara@example.com', 'password' => self::PASS])->assertSessionHasErrors('login');
        $this->assertGuest('web');

        $this->travel(16)->minutes();
        $this->postJson('/api/v1/auth/login', ['login' => '0911223344', 'password' => self::PASS])->assertOk()->assertJsonStructure(['token']);
        $this->assertSame(0, $u->fresh()->failed_logins);
    }

    public function test_a_right_password_resets_the_wrong_count(): void
    {
        $u = $this->customer();
        foreach (range(1, 9) as $i) {
            $this->postJson('/api/v1/auth/login', ['login' => '0911223344', 'password' => 'nope'.$i]);
        }
        $this->assertSame(9, $u->fresh()->failed_logins);
        $this->postJson('/api/v1/auth/login', ['login' => '0911223344', 'password' => self::PASS])->assertOk();
        $this->assertSame(0, $u->fresh()->failed_logins);
        $this->assertNull($u->fresh()->locked_until);
    }

    public function test_admin_accounts_pause_too_and_the_owner_is_alerted(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'boss@example.com', 'password' => 'admin pass phrase 1']);
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/v1/admin/auth/login', ['login' => 'boss@example.com', 'password' => 'bad'.$i]);
        }
        $this->assertNotNull($admin->fresh()->locked_until);
        $this->assertDatabaseHas('activity_log', ['action' => 'security.critical']);
        $this->postJson('/api/v1/admin/auth/login', ['login' => 'boss@example.com', 'password' => 'admin pass phrase 1'])->assertUnprocessable();
    }

    // ── "Was this you?" ───────────────────────────────────────

    public function test_new_browser_sign_in_sends_a_notice_but_a_known_one_does_not(): void
    {
        $r = $this->post('/register', ['first_name' => 'Abel', 'last_name' => 'Tesfaye', 'phone' => '0911000222', 'password' => self::PASS,
            'password_confirmation' => self::PASS, 'terms' => '1']);
        $device = (string) $r->getCookie('km_device')?->getValue();
        $this->assertSame(40, strlen($device));
        $this->assertAlerted('someone signed in', 0); // right after sign-up: expected, no alarm
        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        // Same browser (cookie kept): no notice.
        $this->withCookie('km_device', $device)->post('/login', ['login' => '0911000222', 'password' => self::PASS])->assertRedirect('/account');
        $this->assertAlerted('someone signed in', 0);
        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        // A new browser (no cookie): notice.
        $this->withCookie('km_device', '')->post('/login', ['login' => '0911000222', 'password' => self::PASS])->assertRedirect('/account');
        $this->assertAlerted('someone signed in', 1);
        $this->assertSame(2, DB::table('user_devices')->count());
    }

    public function test_app_sign_in_sends_a_notice(): void
    {
        $this->customer();
        $this->postJson('/api/v1/auth/login', ['login' => '0911223344', 'password' => self::PASS, 'device_name' => 'Pixel 8'])->assertOk();
        $this->assertAlerted('Pixel 8 (app)');
    }

    // ── Password change / sign out everywhere ─────────────────

    public function test_password_change_signs_out_other_devices_and_notifies(): void
    {
        $u = $this->customer();
        $keep = $u->createToken('this')->plainTextToken;
        $u->createToken('other-phone');
        $this->withToken($keep)->putJson('/api/v1/me/password', ['current_password' => self::PASS, 'password' => 'a brand new phrase 9'])->assertOk();

        $this->assertSame(1, $u->tokens()->count());
        $this->assertAlerted('password was changed');
    }

    public function test_sign_out_everywhere_ends_sessions_tokens_and_remember_me(): void
    {
        config(['session.driver' => 'privacy-database']);
        $u = $this->customer();
        $u->forceFill(['remember_token' => 'old-token'])->save();
        DB::table('sessions')->insert([
            ['id' => 'sess-a', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'sess-b', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $keep = $u->createToken('this')->plainTextToken;
        $u->createToken('tablet');

        $this->withToken($keep)->postJson('/api/v1/me/sign-out-all')->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $u->id)->count());
        $this->assertSame(1, $u->tokens()->count());
        $this->assertNotSame('old-token', $u->fresh()->remember_token);
        $this->assertAlerted('signed out on all devices');
    }

    // ── Email change ──────────────────────────────────────────

    public function test_email_changes_only_after_the_new_address_confirms(): void
    {
        $u = $this->customer();
        $this->actingAs($u, 'web')->post('/account/security/email', ['email' => 'new@example.com', 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($u, 'web')->post('/account/security/email', ['email' => 'new@example.com', 'current_password' => self::PASS])->assertRedirect();
        $this->assertSame('sara@example.com', $u->fresh()->email);
        $this->assertSame('new@example.com', $u->fresh()->pending_email);
        $this->assertAlerted('change the email', 1);         // the old address is warned

        $link = null;
        Bus::assertDispatched(SendCustomerMessage::class, function ($job) use (&$link) {
            if ($job->message->email === 'new@example.com' && preg_match('#(/account/email/confirm/[A-Za-z0-9]{48})#', $job->message->body, $m)) {
                $link = $m[1];
            }

            return true;
        });
        $this->assertNotNull($link);
        $this->app['auth']->forgetGuards();
        $this->get($link)->assertRedirect('/login');
        $this->assertSame('new@example.com', $u->fresh()->email);
        $this->get($link)->assertSessionHasErrors('email');   // works once
    }

    public function test_expired_email_link_does_nothing(): void
    {
        $u = $this->customer();
        app(EmailChange::class)->request($u, 'later@example.com');
        $token = null;
        Bus::assertDispatched(SendCustomerMessage::class, function ($job) use (&$token) {
            if (preg_match('#/confirm/([A-Za-z0-9]{48})#', $job->message->body, $m)) {
                $token = $m[1];
            }

            return true;
        });
        $this->travel(25)->hours();
        $this->get('/account/email/confirm/'.$token)->assertSessionHasErrors('email');
        $this->assertSame('sara@example.com', $u->fresh()->email);
    }

    // ── Two-step sign-in for customers ────────────────────────

    public function test_customer_two_factor_on_the_website(): void
    {
        $u = $this->customer();
        $this->actingAs($u, 'web')->post('/account/security/two-factor')->assertRedirect();
        $secret = (string) $u->fresh()->two_factor_secret;
        $this->actingAs($u, 'web')->post('/account/security/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($u, 'web')->post('/account/security/two-factor/confirm', ['code' => Totp::code($secret, intdiv(now()->getTimestamp(), 30))])
            ->assertSessionHas('2fa.codes');
        $this->assertTrue($u->fresh()->hasTwoFactor());
        $this->assertAlerted('two-step sign-in is now ON');
        $recovery = $u->fresh()->two_factor_recovery_codes[0];
        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        // Password alone isn't enough.
        $this->post('/login', ['login' => 'sara@example.com', 'password' => self::PASS])->assertRedirect('/login/two-factor');
        $this->assertGuest('web');
        $this->post('/login/two-factor', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post('/login/two-factor', ['code' => $recovery])->assertRedirect('/account');
        $this->assertAuthenticatedAs($u->fresh(), 'web');
        $this->assertNotContains($recovery, $u->fresh()->two_factor_recovery_codes, 'recovery codes work once');
    }

    public function test_customer_two_factor_in_the_app_api(): void
    {
        $u = $this->customer();
        $token = $u->createToken('a')->plainTextToken;
        $secret = $this->withToken($token)->postJson('/api/v1/me/two-factor')->assertOk()->json('secret');
        $this->withToken($token)->postJson('/api/v1/me/two-factor/confirm', ['code' => Totp::code($secret, intdiv(now()->getTimestamp(), 30))])
            ->assertOk()->assertJsonCount(8, 'recovery_codes');
        $this->app['auth']->forgetGuards();

        $challenge = $this->postJson('/api/v1/auth/login', ['login' => '0911223344', 'password' => self::PASS])
            ->assertOk()->assertJsonPath('two_factor', true)->assertJsonMissing(['token'])->json('challenge');
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => 'forged', 'code' => '000000'])->assertUnprocessable();
        $this->travel(1)->minutes(); // next time step, so the setup code isn't reused
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => Totp::code($secret, intdiv(now()->getTimestamp(), 30))])
            ->assertOk()->assertJsonStructure(['token']);

        // Turning it off needs password + code.
        $t2 = $u->createToken('b')->plainTextToken;
        $this->withToken($t2)->deleteJson('/api/v1/me/two-factor', ['password' => self::PASS, 'code' => '000000'])->assertUnprocessable();
        $this->assertTrue($u->fresh()->hasTwoFactor());
    }

    public function test_challenge_expires(): void
    {
        $u = $this->customer();
        $u->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $challenge = $this->postJson('/api/v1/auth/login', ['login' => '0911223344', 'password' => self::PASS])->json('challenge');
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => Totp::code('JBSWY3DPEHPK3PXP', intdiv(now()->getTimestamp(), 30))])
            ->assertUnprocessable()->assertJsonValidationErrors('challenge');
    }

    // ── Admin session timeout ─────────────────────────────────

    public function test_admin_is_signed_out_after_30_idle_minutes_and_after_12_hours(): void
    {
        $admin = $this->actingAsAdmin();
        $this->withSession(['admin.last_seen_at' => now()->getTimestamp(), 'admin.signed_in_at' => now()->getTimestamp()])
            ->getJson('/api/v1/admin/auth/me')->assertOk();

        $this->withSession(['admin.last_seen_at' => now()->subMinutes(31)->getTimestamp(), 'admin.signed_in_at' => now()->subHour()->getTimestamp()])
            ->getJson('/api/v1/admin/auth/me')->assertUnauthorized()->assertJsonPath('session_expired', true);

        $this->actingAs($admin, 'web');
        $this->withSession(['admin.last_seen_at' => now()->getTimestamp(), 'admin.signed_in_at' => now()->subHours(13)->getTimestamp()])
            ->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
    }

    public function test_app_tokens_expire_after_30_days(): void
    {
        $u = $this->customer();
        $token = $u->createToken('a')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->travel(31)->days();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_security_page_renders_and_needs_sign_in(): void
    {
        $this->get('/account/security')->assertRedirect('/login');
        $u = $this->customer();
        $this->actingAs($u, 'web')->get('/account/security')->assertOk()
            ->assertSee('Two-step sign-in')->assertSee('Sign out all other devices');
        $this->assertTrue(Hash::check(self::PASS, $u->password));
    }
}
