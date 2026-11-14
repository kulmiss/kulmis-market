<?php

namespace Tests\Feature;

use Tests\TestCase;

class ScaffoldTest extends TestCase
{
    public function test_health_endpoint_is_up(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_api_v1_ping(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJson(['ok' => true, 'currency' => 'ETB']);
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/up')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_shop_defaults(): void
    {
        $this->assertSame('ETB', config('shop.currency'));
        $this->assertSame(100, config('shop.minor_units'));
        $this->assertSame('Africa/Addis_Ababa', config('app.timezone'));
    }

    public function test_scheduler_drains_queue_on_shared_hosting(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('queue:work --stop-when-empty')
            ->assertSuccessful();
    }
}
