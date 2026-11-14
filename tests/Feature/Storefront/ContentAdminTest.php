<?php

namespace Tests\Feature\Storefront;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_edits_pages_and_banners(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/v1/admin/pages', ['slug' => 'terms', 'title' => 'Terms', 'body' => '## Terms'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/pages/$id", ['slug' => 'terms', 'title' => 'Terms of sale', 'body' => '## Updated'])->assertOk();
        $this->assertSame('Terms of sale', Page::query()->findOrFail($id)->title);

        $this->postJson('/api/v1/admin/banners', ['placement' => 'home', 'title' => 'Sale', 'link_url' => 'javascript:alert(1)'])->assertUnprocessable();
        $b = $this->postJson('/api/v1/admin/banners', ['placement' => 'home', 'title' => 'Sale', 'link_url' => '/shop'])->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/admin/banners/$b")->assertOk();

        $this->actingAsAdmin('finance');
        $this->getJson('/api/v1/admin/pages')->assertForbidden();
    }
}
