<?php

namespace Tests\Feature\Platform\Concerns;

use App\Badges\BadgeEngine;
use App\Models\Address;
use App\Models\Badge;
use App\Models\Bundle;
use App\Models\Challenge;
use App\Models\Contest;
use App\Models\FlashSale;
use App\Models\Membership;
use App\Models\Order;
use App\Models\PayoutAccount;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Signal;
use App\Models\User;
use App\Notify\Inbox;
use App\Referral\CommissionEngine;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * Test data where every feature has something to show: a member with a team,
 * earnings, a badge, a goal, an address, an order, contests, signals, a flash
 * sale, a bundle, reviews, a download, an add-on, a pre-order, a sold-out
 * product, a members-only product, a cart. Plus a new member, an expired
 * member and a shopper. Uses ReferralTestCase helpers.
 */
trait BuildsEveryFeature
{
    protected User $member;

    protected User $shopper;

    protected User $newMember;

    protected User $expired;

    protected ?string $cart = null;

    /** @var array<string, string> */
    protected array $slugs = [];

    protected function allOn(): void
    {
        foreach (array_keys(config('features')) as $key) {
            Features::set($key, true);
        }
        Settings::set('wallet.cashout_fee_fixed', 1_000);
        config(['services.webpush.public_key' => 'BPublicKeyForTheTestOnly', 'services.webpush.private_key' => 'private-key-for-the-test']);
    }

    /** Data so that every feature has something to show. */
    protected function fixture(): void
    {
        $boss = $this->member(null, 'Boss');
        $this->member = $this->member($boss, 'Selam');
        $team = $this->member($this->member, 'Hiwot');
        $this->payMembership($team);
        $this->payMembership($this->member($team, 'Kebede'));
        $this->travel(15)->days(); // earnings are ready after 14 days; memberships still running
        app(CommissionEngine::class)->releaseDue();
        $this->payMembership($this->member($this->member, 'Mulu')); // a new paying member this month: top referrers
        $this->member->forceFill(['goal_amount' => 500_000])->save();
        Address::query()->forceCreate(['user_id' => $this->member->id, 'label' => 'Home', 'first_name' => 'Selam', 'phone' => '0911000000',
            'city' => 'Adama', 'address' => 'Kebele 04', 'is_default' => true]);
        app(BadgeEngine::class)->evaluate($this->member);
        Badge::query()->update(['is_active' => true]);

        $this->shopper = User::factory()->create(['first_name' => 'Abebe']);
        $this->newMember = $this->member(null, 'Almaz');
        $this->expired = $this->member(null, 'Tesfaye');
        Membership::query()->where('user_id', $this->expired->id)->update(['status' => 'expired',
            'current_period_end' => now()->subDays(20), 'grace_ends_at' => now()->subDays(17)]);
        [$p, $sale, $b1, $b2] = Product::factory()->count(4)->create(['price' => 100_000, 'stock' => 20, 'cost_materials' => 20_000, 'cost_labour' => 0, 'cost_packaging' => 0]);
        $digital = Product::factory()->create(['price' => 15_000, 'kind' => Product::DIGITAL, 'name' => 'Guide download']);
        $pre = Product::factory()->create(['price' => 90_000, 'stock' => 5, 'preorder_deposit_bps' => 3000, 'preorder_ships_on' => now()->addDays(20)->toDateString()]);
        $addon = Product::factory()->create(['price' => 50_000, 'stock' => 8, 'kind' => Product::ADDON, 'name' => 'Coffee class seat']);
        $soldOut = Product::factory()->create(['price' => 70_000, 'stock' => 0]);
        Product::factory()->create(['price' => 120_000, 'stock' => 3, 'is_members_only' => true, 'name' => 'Members basket']);
        $this->slugs = ['plain' => $p->slug, 'sale' => $sale->slug, 'bundle' => $b1->slug, 'digital' => $digital->slug, 'preorder' => $pre->slug,
            'addon' => $addon->slug, 'soldout' => $soldOut->slug];
        FlashSale::query()->create(['product_id' => $sale->id, 'price' => 80_000, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'stock_limit' => 10]);
        $bundle = Bundle::query()->create(['name' => 'Coffee set', 'slug' => 'coffee-set', 'price' => 180_000, 'is_active' => true]);
        foreach ([$b1, $b2] as $x) {
            $bundle->items()->create(['product_id' => $x->id, 'quantity' => 1]);
        }
        ProductReview::query()->create(['product_id' => $p->id, 'user_id' => $this->shopper->id, 'rating' => 5, 'body' => 'Lovely basket', 'status' => 'approved']);
        $this->buyProducts(100_000, $this->member);
        // Someone bought the plain product with a bundle product: "Frequently bought together".
        $t = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $t])->postJson('/api/v1/cart/items', ['product_id' => $b2->id]);
        $this->withHeaders(['X-Cart-Token' => $t])->postJson('/api/v1/checkout', ['checkout_key' => 'together-checkout-0001', 'first_name' => 'Liya', 'phone' => '0911223344', 'city' => 'Adama', 'address' => 'x']);
        $this->approve(Order::query()->latest('id')->firstOrFail());
        $this->flushHeaders();
        // Enough in the wallet to pay part of a membership from it, and a top referrer this month.
        PayoutAccount::query()->forceCreate(['user_id' => $this->member->id, 'method' => 'telebirr', 'account_name' => 'Selam',
            'account_number' => '0911000000', 'account_fingerprint' => hash('sha256', 'telebirr0911000000'), 'status' => 'active', 'usable_after' => now()->subDay()]);
        Cache::flush(); // nothing remembered from before the orders were paid
        Contest::query()->create(['name' => 'Spring race', 'rule' => 'new_paying_members', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'prizes' => [10_000]]);
        Challenge::query()->create(['name' => 'Bring 3', 'target' => 3, 'reward' => 20_000]);
        Signal::query()->create(['pair' => 'XAU/USD', 'direction' => 'long', 'entry' => '1', 'targets' => ['2'], 'stop_loss' => '0',
            'status' => 'closed', 'result' => 'win', 'closed_at' => now()->subDay(), 'locked_at' => now(), 'posted_at' => now()->subDays(2)]);
        Inbox::add($this->member, 'earning', 'You earned 100 ETB', null, null, 'fixture-1');
        $this->cart = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->app['auth']->forgetGuards();
    }
}
