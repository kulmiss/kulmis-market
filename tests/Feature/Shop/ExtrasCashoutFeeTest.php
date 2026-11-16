<?php

namespace Tests\Feature\Shop;

use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\Order;
use App\Models\PayoutAccount;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\User;
use App\Support\Features;
use App\Support\Settings;
use App\Wallet\CashOuts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Paid checkout extras (off by default) and the cash-out fee (off, 0 by default). */
class ExtrasCashoutFeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_extras_are_off_by_default_and_priced_by_the_server(): void
    {
        ShippingZone::query()->create(['name' => 'All', 'cities' => [], 'base_fee' => 10_000, 'is_default' => true]);
        $p = Product::factory()->create(['price' => 200_000]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $h = ['X-Cart-Token' => $token];
        $this->withHeaders($h)->putJson('/api/v1/cart/extras', ['extras' => ['gift_wrap']])->assertOk()->assertJsonPath('extras', []);
        $this->get('/checkout')->assertDontSee('Extras (optional)');

        Features::set('shop.extras.gift_wrap', true);
        Features::set('shop.extras.insurance', true);
        $this->withHeaders($h)->putJson('/api/v1/cart/extras', ['extras' => ['gift_wrap', 'insurance', 'express']])->assertOk()
            ->assertJsonPath('extras', ['gift_wrap', 'insurance'])     // express is off
            ->assertJsonPath('quote.extras_total', 15_000 + 3_000);     // 1.5% of 2,000 ETB = 30 ETB
        $this->withHeaders($h)->putJson('/api/v1/cart/extras', ['extras' => ['free_money']])->assertJsonValidationErrors('extras.0');

        $this->withHeaders($h)->postJson('/api/v1/checkout', ['checkout_key' => (string) Str::uuid(), 'first_name' => 'A', 'phone' => '0911000901',
            'city' => 'Adama', 'address' => 'x'])->assertCreated();
        $o = Order::query()->firstOrFail();
        $this->assertSame(18_000, $o->extras_total);
        $this->assertSame(200_000 + 10_000 + 18_000, $o->total);
        $this->assertSame(200_000, $o->merchandise_total); // team earnings are not paid on extras
        $this->assertSame(['gift_wrap', 'insurance'], array_column($o->extras, 'key'));
    }

    public function test_cash_out_fee_is_kept_by_the_shop_and_shown_first(): void
    {
        $this->assertSame(0, CashOuts::fee(100_000)); // off by default
        Features::set('wallet.cashout_fee', true);
        $this->assertSame(0, CashOuts::fee(100_000)); // on, but 0 by default
        Settings::set('wallet.cashout_fee_fixed', 1_000);
        Settings::set('wallet.cashout_fee_bps', 100);
        $this->assertSame(1_000 + 1_000, CashOuts::fee(100_000));
        $this->assertSame(99, CashOuts::fee(100)); // never the whole amount

        $u = User::factory()->member()->create(['created_at' => now()->subYear()]);
        $ledger = app(Ledger::class);
        $ledger->post('manual.adjust', [[$ledger->userAccount($u, Wallet::AVAILABLE), -300_000], ['equity:adjustments', 300_000]], null, null, 'test');
        PayoutAccount::query()->forceCreate(['user_id' => $u->id, 'method' => 'telebirr', 'account_name' => $u->first_name.' '.$u->last_name,
            'account_number' => '0911000902', 'account_fingerprint' => hash('sha256', 'x'), 'usable_after' => now()->subDay()]);
        $this->actingAs($u)->get('/account/wallet')->assertSee('Cash-out fee:');
        $r = app(CashOuts::class)->request($u, 200_000);
        $this->assertSame(3_000, $r->fee);
        app(CashOuts::class)->markPaid($r, 'TX', User::factory()->superAdmin()->create()->id, true);
        $this->assertSame(3_000, $ledger->balance(CashOuts::FEE_ACCOUNT));
        $this->assertSame(-197_000, $ledger->balance('asset:cash'));
    }
}
