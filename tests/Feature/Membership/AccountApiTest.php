<?php

namespace Tests\Feature\Membership;

use App\Jobs\SendCustomerMessage;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_and_password_change(): void
    {
        $u = User::factory()->create(['password' => 'oldpass123']);
        $token = $u->createToken('a')->plainTextToken;
        $other = $u->createToken('b')->plainTextToken;

        $before = $u->email;
        // A new email waits for the link sent to it.
        $this->withToken($token)->putJson('/api/v1/me', ['first_name' => 'Meron', 'last_name' => 'Alemu', 'email' => 'M@Example.com'])
            ->assertOk()->assertJsonPath('user.email', $before)->assertJsonPath('user.pending_email', 'm@example.com')
            ->assertJsonPath('email_confirmation_sent', true);
        $this->withToken($token)->putJson('/api/v1/me/password', ['current_password' => 'nope', 'password' => 'brand-new-pass-123'])->assertUnprocessable();
        $this->withToken($token)->putJson('/api/v1/me/password', ['current_password' => 'oldpass123', 'password' => 'brand-new-pass-123'])->assertOk();
        $this->assertTrue(Hash::check('brand-new-pass-123', $u->fresh()->password));
        $this->assertSame(1, $u->tokens()->count(), 'other sessions signed out');
    }

    public function test_sms_password_reset(): void
    {
        Bus::fake([SendCustomerMessage::class]);
        $u = User::factory()->create(['phone' => '+251911000555']);
        $this->postJson('/api/v1/auth/forgot', ['phone' => '0911000555'])->assertOk();
        $this->postJson('/api/v1/auth/forgot', ['phone' => '0911999999'])->assertOk(); // same answer for unknown

        $code = null;
        Bus::assertDispatched(SendCustomerMessage::class, function ($job) use (&$code) {
            preg_match('/code is (\d{6})/', $job->message->body, $m);
            $code = $m[1] ?? null;

            return $code !== null;
        });
        Bus::assertDispatchedTimes(SendCustomerMessage::class, 1);

        $this->postJson('/api/v1/auth/reset', ['phone' => '0911000555', 'code' => '000000', 'password' => 'fresh-start-1234'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/reset', ['phone' => '0911000555', 'code' => $code, 'password' => 'fresh-start-1234'])->assertOk();
        $this->assertTrue(Hash::check('fresh-start-1234', $u->fresh()->password));
        $this->postJson('/api/v1/auth/reset', ['phone' => '0911000555', 'code' => $code, 'password' => 'again-and-again-1234'])->assertUnprocessable();
    }

    public function test_reset_code_expires_and_locks_after_5_attempts(): void
    {
        Bus::fake([SendCustomerMessage::class]);
        User::factory()->create(['phone' => '+251911000556']);
        $this->postJson('/api/v1/auth/forgot', ['phone' => '0911000556']);
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/reset', ['phone' => '0911000556', 'code' => '111111', 'password' => 'fresh-start-1234']);
        }
        $this->assertSame(5, (int) \DB::table('password_reset_codes')->value('attempts'));
    }

    public function test_order_history_is_private(): void
    {
        $u = User::factory()->create();
        $other = User::factory()->create();
        $mk = fn (User $owner, string $n) => Order::query()->create(['number' => $n, 'user_id' => $owner->id, 'first_name' => 'X', 'phone' => '+251911000000',
            'city' => 'A', 'address' => 'B', 'list_subtotal' => 100, 'merchandise_total' => 100, 'total' => 100, 'checkout_key' => $n]);
        $mk($u, 'KM261115-00001');
        $mk($other, 'KM261115-00002');

        $this->actingAs($u, 'sanctum')->getJson('/api/v1/me/orders')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/me/orders/KM261115-00001')->assertOk();
        $this->getJson('/api/v1/me/orders/KM261115-00002')->assertNotFound();
    }

    public function test_api_errors_are_json_and_unauthenticated_is_401(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonStructure(['message']);
        $this->get('/api/v1/me')->assertUnauthorized(); // even without Accept: json
        $this->getJson('/api/v1/nope')->assertNotFound()->assertJsonStructure(['message']);
    }
}
