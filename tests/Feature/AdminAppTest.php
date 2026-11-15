<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminAppTest extends TestCase
{
    public function test_admin_app_is_served_for_deep_links(): void
    {
        if (! is_file(public_path('admin-app/index.html'))) {
            $this->markTestSkipped('Admin not built.');
        }
        $this->get('/admin')->assertOk()->assertSee('<div id="root">', false);
        $this->get('/admin/orders/123')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }
}
