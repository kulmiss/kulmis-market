<?php

namespace Tests\Feature\Referral;

use App\Models\Commission;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShareKitItem;
use App\Support\Features;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Product share links, the share kit and the earnings tips card. */
class ShareKitTipsTest extends ReferralTestCase
{
    public function test_product_link_remembers_the_code_and_the_sale_is_a_referral_sale(): void
    {
        [, , $l1] = $this->chain();
        $p = Product::factory()->create(['slug' => 'mesob-basket', 'price' => 100_000]);
        $this->actingAs($l1)->get('/shop/mesob-basket')->assertSee('Share and earn')
            ->assertSee('/r/'.$l1->referral_code.'/shop/mesob-basket', false)->assertSee('data-card', false);
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $res = $this->get('/r/'.$l1->referral_code.'/shop/mesob-basket');
        $res->assertRedirect('/shop/mesob-basket?ref='.$l1->referral_code);
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === config('referral.cookie'));
        $this->assertNotNull($cookie);

        $code = (string) $l1->referral_code; // the browser now carries the code
        $token = $this->withCredentials()->withCookie(config('referral.cookie'), $code)->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->withCookie(config('referral.cookie'), $code)->postJson('/api/v1/checkout', [
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'G', 'phone' => '0977000555', 'city' => 'Adama', 'address' => 'x'])->assertSuccessful();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame($l1->id, $order->referrer_user_id);
        $this->approve($order);
        $this->assertTrue(Commission::query()->where('recipient_id', $l1->id)->where('type', 'products')->exists());
    }

    public function test_share_links_never_leave_the_site(): void
    {
        $this->get('/r/ABC12/https://evil.example')->assertNotFound();
        $this->get('/r/ABC12/shop/../../etc')->assertNotFound();
        $this->get('/r/ABC12/membership')->assertRedirect('/membership?ref=ABC12');
        $this->get('/r/ABC12/shop')->assertRedirect('/shop?ref=ABC12');
        $u = $this->member();
        $this->actingAs($u)->get('/account/referral-qr.svg?to=signals')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->get('/account/referral-qr.svg?to=//evil.example')->assertNotFound();
    }

    public function test_share_kit_fills_in_the_members_code_and_admin_edits_it(): void
    {
        $u = $this->member(null, 'Selam');
        $this->actingAs($u)->get('/account/share')->assertOk()->assertSee('Membership')
            ->assertSee($u->fresh()->referral_code)->assertSee('/r/'.$u->fresh()->referral_code.'/membership', false)->assertSee('facebook.com/sharer', false)
            // A post without its own picture shows the square shop icon, not a corner of the wide link picture.
            ->assertSee('src="'.asset('apple-touch-icon.png').'"', false);

        Storage::fake('public');
        $this->actingAsAdmin();
        $this->post('/api/v1/admin/share-kit', ['title' => 'Coffee', 'target' => 'shop', 'text' => 'no link here'], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('text');
        $id = $this->post('/api/v1/admin/share-kit', ['title' => 'Coffee set', 'target' => 'shop', 'text' => 'Buna time ☕ {link} — {name}',
            'image' => UploadedFile::fake()->image('c.jpg', 1600, 1200)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $item = ShareKitItem::query()->findOrFail($id);
        Storage::disk('public')->assertExists((string) $item->image_path);
        $this->post("/api/v1/admin/share-kit/$id", ['title' => 'Coffee set', 'target' => 'shop', 'text' => '{link}', 'is_active' => '0', 'remove_image' => '1'],
            ['Accept' => 'application/json'])->assertOk();
        $this->assertNull($item->fresh()->image_path);
        $this->assertSame(4, ShareKitItem::query()->count());

        Features::set('referral.share_kit', false);
        $this->actingAs($u)->get('/account/share')->assertNotFound();
    }

    public function test_tips_card_suggests_the_next_step(): void
    {
        $u = $this->member(null, 'Tigist');
        $this->actingAs($u)->get('/account')->assertSee('Earn more: tips for you')->assertSee('two family or friends groups');
        $this->member($u, 'Waiting')->membership()->delete(); // signed up, not paid
        $this->get('/account')->assertSee('did not pay yet');
        Features::set('referral.tips', false);
        $this->get('/account')->assertDontSee('Earn more: tips for you');
    }
}
