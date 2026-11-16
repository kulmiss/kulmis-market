<?php

namespace Tests\Feature\Security;

use App\Models\Bundle;
use App\Models\Contest;
use App\Models\Faq;
use App\Models\FlashSale;
use App\Models\GiftCard;
use App\Models\Product;
use App\Models\ShareKitItem;
use App\Support\Settings;
use Tests\Feature\Referral\ReferralTestCase;

/**
 * Round 5 security pass: who may create money, what admins type is shown as
 * text (never run as code), and extra fields in a form are ignored.
 */
class NewFeaturesSecurityTest extends ReferralTestCase
{
    private const XSS = '<script>alert(1)</script><img src=x onerror=alert(2)>';

    public function test_only_managers_and_super_admin_can_sell_a_gift_card_for_cash(): void
    {
        $amount = ['amount' => 100_000, 'buyer_name' => 'Cash buyer'];
        foreach (['fulfilment', 'support'] as $role) {
            $this->app['auth']->forgetGuards();
            $this->actingAsAdmin($role);
            $this->getJson('/api/v1/admin/gift-cards')->assertOk(); // they can still look up a card for a customer
            $this->postJson('/api/v1/admin/gift-cards/sell', $amount)->assertForbidden();
        }
        $this->assertSame(0, GiftCard::query()->count());
        $this->app['auth']->forgetGuards();
        $this->actingAsAdmin('manager');
        $this->postJson('/api/v1/admin/gift-cards/sell', $amount)->assertCreated();
        $this->assertSame(1, GiftCard::query()->where('status', 'active')->count());
    }

    public function test_text_typed_by_admins_is_shown_as_text(): void
    {
        $member = $this->member(null, 'Selam');
        Contest::query()->create(['name' => 'Race '.self::XSS, 'description' => self::XSS, 'rule' => 'new_paying_members',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'prizes' => [10_000]]);
        ShareKitItem::query()->create(['title' => 'Post '.self::XSS, 'target' => array_key_first(ShareKitItem::TARGETS), 'text' => self::XSS.' {link}', 'position' => 0, 'is_active' => true]);
        Faq::query()->create(['question' => 'Q '.self::XSS, 'answer' => self::XSS, 'is_published' => true, 'position' => 0]);
        [$a, $b] = Product::factory()->count(2)->create(['price' => 100_000, 'stock' => 9]);
        $bundle = Bundle::query()->create(['name' => 'Set '.self::XSS, 'slug' => 'set-x', 'description' => self::XSS, 'price' => 150_000, 'is_active' => true]);
        foreach ([$a, $b] as $p) {
            $bundle->items()->create(['product_id' => $p->id, 'quantity' => 1]);
        }

        $pages = [
            $this->actingAs($member)->get('/account/contests'),
            $this->actingAs($member)->get('/account/share'),
            $this->get('/help'),
            $this->get('/shop/'.$a->slug),
        ];
        foreach ($pages as $i => $res) {
            $res->assertOk();
            $html = (string) $res->getContent();
            $this->assertStringNotContainsString('<script>alert(1)', $html, "page $i runs admin text as code");
            $this->assertStringNotContainsString('<img src=x onerror', $html, "page $i runs admin text as code");
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, "page $i should show the text");
        }
    }

    public function test_extra_fields_in_admin_forms_are_ignored(): void
    {
        Settings::set('contest.budget', 500);
        $this->actingAsAdmin();
        $p = Product::factory()->create(['price' => 200_000, 'stock' => 9]);
        $this->postJson('/api/v1/admin/flash-sales', ['product_id' => $p->id, 'price' => 190_000, 'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addDay()->toIso8601String(), 'sold' => 99, 'created_by' => 1, 'is_demo' => true, 'accept_loss' => true])->assertCreated();
        $sale = FlashSale::query()->firstOrFail();
        $this->assertSame(0, $sale->sold);
        $this->assertFalse((bool) $sale->getAttribute('is_demo'));

        $this->postJson('/api/v1/admin/contests', ['name' => 'Race', 'rule' => 'new_paying_members', 'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addDays(7)->toIso8601String(), 'prizes' => [10_000], 'status' => 'paid', 'paid_total' => 999_999, 'result' => ['x']])->assertCreated();
        $c = Contest::query()->firstOrFail();
        $this->assertSame('active', $c->status);
        $this->assertSame(0, (int) $c->paid_total);
        $this->assertNull($c->result);
    }
}
