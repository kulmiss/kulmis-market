<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PrivacySessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sessions_store_no_ip_or_user_agent(): void
    {
        config(['session.driver' => 'privacy-database']);
        Route::middleware('web')->get('/_t/session', function () {
            session(['x' => 1]);

            return 'ok';
        });

        $this->withServerVariables(['REMOTE_ADDR' => '196.188.1.1'])->get('/_t/session')->assertOk();

        $row = DB::table('sessions')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->ip_address);
        $this->assertNull($row->user_agent);
    }
}
