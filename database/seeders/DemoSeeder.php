<?php

namespace Database\Seeders;

use App\Badges\BadgeEngine;
use App\Cart\CartManager;
use App\Catalog\ImageProcessor;
use App\Demo\Demo;
use App\Fraud\FraudGuard;
use App\Jobs\EvaluateBadges;
use App\Jobs\SendCustomerMessage;
use App\Ledger\Wallet;
use App\Membership\MembershipService;
use App\Models\Bundle;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Challenge;
use App\Models\Contest;
use App\Models\Coupon;
use App\Models\Courier;
use App\Models\CustomRequest;
use App\Models\FlashSale;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PayoutRequest;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductReview;
use App\Models\ShippingZone;
use App\Models\Signal;
use App\Models\SignalSubscription;
use App\Models\User;
use App\Orders\Checkout;
use App\Orders\OrderService;
use App\Payments\PaymentService;
use App\Referral\CommissionEngine;
use App\Referral\ReferralCodes;
use App\Shop\GiftCards;
use App\Signals\SignalAccess;
use App\Wallet\CashOuts;
use App\Wallet\PayoutAccounts;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Demo data for every section: `php artisan db:seed --class=DemoSeeder`.
 *
 * Six months of shop life are simulated through the real services (sign-ups,
 * membership payments, orders, commissions, cash-outs, signals…), so every
 * number on the dashboards adds up. Every row gets is_demo = true: hide it
 * with "Show demo data" or delete it in Admin → Demo data.
 *
 * Refuses to run when APP_ENV=production. DEMO_CATALOG_ONLY=1 adds just the
 * catalog (used by Lighthouse CI).
 */
class DemoSeeder extends Seeder
{
    /** Password of every demo account (documented in docs/local-setup.md). */
    public const PASSWORD = 'kulmis demo 2026';

    public const DAYS = 180;

    private int $days = self::DAYS;

    private int $placed = 0;

    private User $admin;

    private string $hash;

    /** @var array<string, User> */
    private array $named = [];

    /** @var list<array{0: User, 1: int}> member + day joined */
    private array $members = [];

    /** @var list<Product> */
    private array $buyable = [];

    private int $phoneSeq = 0;

    /** @var array<string, bool> */
    private array $decided = [];

    /** @var array<int, array{0:int, 1:string}> day => [user id, what happens] */
    private array $pendingCashOut = [];

    /** @var array<int, int> product id => stock at the end (low / sold out on purpose) */
    private array $finalStock = [];

    public function run(): void
    {
        Demo::assertAllowed();
        $this->call([ShopBasicsSeeder::class, PagesSeeder::class]); // real defaults, not demo
        if (Demo::exists()) {
            $this->command?->warn('Demo data is already there. Delete it in Admin → Demo data first to add it again.');

            return;
        }
        mt_srand(2026);
        $this->days = max(14, (int) (self::flag('DEMO_DAYS') ?? self::DAYS)); // shorter history for tests
        $this->hash = Hash::make(self::PASSWORD);
        // Demo phones are +251 900… and demo emails end in .test: nothing is ever sent to them.
        Bus::fake([SendCustomerMessage::class, EvaluateBadges::class]);

        try {
            Demo::capture(function () {
                $this->catalog();
                if (! self::flag('DEMO_CATALOG_ONLY')) {
                    $this->simulate();
                }
            });
        } finally {
            Carbon::setTestNow();
        }
        $this->command?->info('Demo data added. Accounts: docs/local-setup.md (password "'.self::PASSWORD.'").');
    }

    /* ------------------------------------------------------------------ catalog */

    private function catalog(): void
    {
        $cats = [];
        foreach ([
            ['Baskets', 'Mesob and woven baskets, made by hand'],
            ['Textiles', 'Handwoven scarves, shawls and blankets'],
            ['Pottery', 'Clay pots, cups and coffee pots'],
            ['Leather', 'Bags and small leather goods'],
            ['Coffee & spices', 'Fresh coffee, berbere and mitmita'],
            ['Jewellery', 'Beads, silver and stones'],
            ['Home decor', 'Things that make a home warm'],
        ] as $i => [$name, $desc]) {
            $cats[$name] = Category::query()->create(['name' => $name, 'slug' => Str::slug($name), 'description' => $desc, 'position' => $i]);
        }

        // name, category, price (birr), cost (birr), photo, extra
        $items = [
            ['Mesob basket, red & black', 'Baskets', 3500, 1200, 'baskets-geometric', ['featured' => true, 'variants' => [['Small (40 cm)', null, 6], ['Large (60 cm)', 4800, 3]]]],
            ['Colourful wall basket set', 'Baskets', 2400, 800, 'baskets-colorful', ['featured' => true]],
            ['Woven bread basket', 'Baskets', 1800, 600, 'baskets-warm', []],
            ['Market tote basket', 'Baskets', 1450, 500, 'baskets-market', ['stock' => 2]],
            ['Lidded storage basket', 'Baskets', 2900, 950, 'baskets-stacked', ['made' => 12]],
            ['Gift basket trio', 'Baskets', 4200, 1500, 'baskets-array', ['members' => true]],
            ['Fine-weave serving tray', 'Baskets', 1650, 520, 'wicker-closeup', ['stock' => 0]],
            ['Handwoven gabi blanket', 'Textiles', 5200, 1900, 'scarves-rack', ['featured' => true, 'variants' => [['Natural', null, 5], ['Natural with tibeb border', 5900, 2]]]],
            ['Tibeb cotton scarf', 'Textiles', 1600, 550, 'scarves-traditional', ['variants' => [['Green & gold', null, 9], ['Red & gold', null, 7], ['Blue & silver', null, 1]]]],
            ['Netela shawl, soft cotton', 'Textiles', 2100, 700, 'scarves-ladder', []],
            ['Kemis dress fabric (3 m)', 'Textiles', 6800, 2600, 'scarves-traditional', ['made' => 21, 'early' => true]],
            ['Jebena coffee pot', 'Pottery', 1400, 450, 'jebena-pot', []],
            ['Clay cooking pot (dist)', 'Pottery', 1250, 400, 'clay-pot-shelf', []],
            ['Sini coffee cups, set of 6', 'Pottery', 950, 300, 'ceramic-cups', ['variants' => [['White', null, 12], ['Earth brown', null, 8]]]],
            ['Clay water jug', 'Pottery', 1100, 350, 'clay-jug', []],
            ['Clay incense burner', 'Pottery', 900, 300, 'mini-clay-pots', ['stock' => 3]],
            ['Terracotta serving set', 'Pottery', 3300, 1100, 'terracotta-set', ['made' => 14]],
            ['Earthenware storage jar', 'Pottery', 2600, 850, 'earthenware', []],
            ['Clay pot trio', 'Pottery', 1950, 650, 'clay-pots-earthy', ['early' => true]],
            ['Leather shoulder bag', 'Leather', 7800, 2800, 'leather-shop', ['featured' => true, 'members' => true, 'variants' => [['Tan', null, 4], ['Dark brown', null, 2]]]],
            ['Leather weekend bag', 'Leather', 9500, 3500, 'leather-handmade', ['made' => 18]],
            ['Leather tote', 'Leather', 5600, 2000, 'leather-kenya', []],
            ['Leather messenger bag', 'Leather', 6900, 2500, 'leather-wheel', []],
            ['Leather card holder', 'Leather', 1200, 400, 'leather-artisan', []],
            ['Yirgacheffe coffee beans, 1 kg', 'Coffee & spices', 1350, 600, 'coffee-sacks', ['variants' => [['Whole beans', null, 40], ['Ground for jebena', null, 25]]]],
            ['Fresh roasted coffee, 500 g', 'Coffee & spices', 750, 330, 'coffee-beans-mug', []],
            ['Coffee ceremony starter set', 'Coffee & spices', 3900, 1400, 'coffee-grinding', ['members' => true]],
            ['Berbere spice blend, 500 g', 'Coffee & spices', 480, 160, 'spice-clay-pot', []],
            ['Mitmita & spice trio', 'Coffee & spices', 890, 300, 'spices-bowls', []],
            ['Spice market gift box', 'Coffee & spices', 1600, 560, 'spices-market', ['stock' => 1]],
            ['Cinnamon & cardamom pack', 'Coffee & spices', 520, 180, 'spices-cinnamon', []],
            ['Beaded necklace, Omo colours', 'Jewellery', 1100, 350, 'beads-necklaces', []],
            ['Handmade bead necklace', 'Jewellery', 1350, 420, 'beads-handmade', []],
            ['Beaded bracelet set', 'Jewellery', 650, 200, 'beads-bracelets', ['variants' => [['Set of 3', null, 15], ['Set of 6', 1150, 6]]]],
            ['Market bead collection', 'Jewellery', 2200, 700, 'beads-accra', []],
            ['Smoky quartz bead bracelet', 'Jewellery', 2900, 1000, 'quartz-bracelet', ['made' => 10, 'featured' => true]],
            ['Macramé wall hanging', 'Home decor', 1500, 400, 'macrame-hanging', []],
        ];
        $images = app(ImageProcessor::class);
        foreach ($items as $i => [$name, $cat, $price, $cost, $photo, $x]) {
            $made = $x['made'] ?? null;
            $p = Product::query()->create([
                'name' => $name, 'slug' => Str::slug($name), 'category_id' => $cats[$cat]->id, 'price' => $price * 100,
                'cost_materials' => (int) ($cost * 60), 'cost_labour' => (int) ($cost * 35), 'cost_packaging' => (int) ($cost * 5),
                'short_description' => $this->blurb($cat),
                'description' => "Made by hand by our partner makers in Ethiopia, so every piece is a little different.\n\n- Natural materials\n- Fair price to the maker\n- Ships all over Ethiopia",
                'weight_grams' => 300 + 120 * ($i % 9), 'stock_mode' => $made ? Product::MADE_TO_ORDER : Product::STOCK,
                'stock' => $made ? null : ($x['stock'] ?? 6 + ($i * 7) % 20), 'making_days' => $made,
                'is_featured' => $x['featured'] ?? false, 'is_members_only' => $x['members'] ?? false,
                'release_at' => ($x['early'] ?? false) ? now()->addDays(2) : null, 'status' => 'active',
            ]);
            foreach ($x['variants'] ?? [] as $v => [$vName, $vPrice, $vStock]) {
                $p->variants()->create(['sku' => strtoupper(Str::random(3)).'-'.($i + 1).'-'.($v + 1), 'name' => $vName, 'options' => ['option' => $vName],
                    'price' => $vPrice ? $vPrice * 100 : null, 'stock' => $made ? null : $vStock, 'position' => $v, 'is_active' => true]);
            }
            $file = database_path("seeders/demo-photos/$photo.jpg");
            if (in_array(self::flag('DEMO_PHOTOS'), ['0', 'false'], true)) {
                $file = $this->tinyPhoto($i); // tests: same flow, small file
            }
            $images->store($p, new UploadedFile($file, "$photo.jpg", 'image/jpeg', null, true), $name);
            if (isset($x['stock'])) {
                $this->finalStock[$p->id] = $x['stock'];
            }
            if (! $p->is_members_only && ! $p->release_at && ($p->stock_mode === Product::MADE_TO_ORDER || $p->stock > 0 || $p->variants()->exists())) {
                $this->buyable[] = $p;
            }
        }

        foreach ([
            ['WELCOME10', 'percent', 1000, 0, null, null, ['max_uses_per_customer' => 1, 'note' => 'First order']],
            ['SEASON15', 'percent', 1500, 200_000, now()->subMonths(2), now()->subMonths(2)->addDays(10), ['note' => 'Season sale (ended)']],
            ['MEMBERS200', 'fixed', 20_000, 150_000, null, null, ['members_only' => true]],
            ['COFFEE5', 'percent', 500, 0, null, now()->addMonth(), ['max_uses' => 200]],
            ['OLDCODE', 'fixed', 10_000, 0, null, null, ['is_active' => false, 'note' => 'Switched off']],
        ] as [$code, $type, $value, $min, $start, $end, $x]) {
            Coupon::query()->create(array_merge(['code' => $code, 'type' => $type, 'value' => $value, 'min_subtotal' => $min,
                'starts_at' => $start, 'ends_at' => $end, 'max_uses_per_customer' => null], $x));
        }
        ShippingZone::query()->create(['name' => 'Addis express (same day)', 'cities' => ['Addis Ababa'], 'base_fee' => 35_000, 'per_kg_fee' => 5_000,
            'eta_days_min' => 0, 'eta_days_max' => 1, 'position' => 0, 'is_active' => false]);
        Courier::query()->create(['name' => 'Addis Bike Delivery', 'phone' => '+251900000001', 'tracking_url' => 'https://example.test/track/{number}']);
    }

    private function blurb(string $cat): string
    {
        return match ($cat) {
            'Baskets' => 'Tightly woven by hand from natural grass, dyed with care.',
            'Textiles' => 'Soft handwoven cotton from Gamo and Addis weavers.',
            'Pottery' => 'Shaped and fired by hand in small batches.',
            'Leather' => 'Full-grain leather, cut and stitched by hand.',
            'Coffee & spices' => 'Fresh from Ethiopian farms and kitchens.',
            'Jewellery' => 'Beads and silver, strung and carved by hand.',
            default => 'Handmade in Ethiopia.',
        };
    }

    /* --------------------------------------------------------------- the story */

    private function simulate(): void
    {
        $start = now()->subDays($this->days)->startOfDay();
        $this->at($start, 9);
        $this->staff();
        $this->signalsHistory($start);

        // Who joins when: the star member first, her team over the months, a few other teams.
        $plan = $this->signupPlan();
        $memberships = app(MembershipService::class);

        for ($day = 0; $day <= $this->days; $day++) {
            $this->at($start->copy()->addDays($day), 8);
            foreach ($plan[$day] ?? [] as [$key, $first, $last, $referrerKey, $pays]) {
                $user = $this->person($first, $last, $referrerKey ? ($this->named[$referrerKey] ?? null) : null, $key);
                if ($pays) {
                    $this->payMembership($user);
                    $this->members[] = [$user, $day];
                }
            }
            $this->at($start->copy()->addDays($day), 11);
            $this->renewals($day);
            if ($day % 10 === 0) {
                $this->restock();
            }
            $this->at($start->copy()->addDays($day), 14);
            $this->ordersFor($day);
            $this->at($start->copy()->addDays($day), 18);
            $this->progressOrders();
            $this->moneyEvents($day);
            $this->at($start->copy()->addDays($day), 23);
            app(CommissionEngine::class)->releaseDue();
            if ($day % 15 === 14) {                                  // badges unlock over time, like in real life
                foreach ($this->members as [$u]) {
                    app(BadgeEngine::class)->evaluate($u);
                }
            }
            $memberships->sweep(now());
            $memberships->grantLoyaltyRewards(now());
            $this->cancelStaleOrders();
        }

        Carbon::setTestNow();
        $this->finishingTouches();
    }

    /** Makers deliver new stock every ten days. */
    private function restock(): void
    {
        Product::query()->where('stock_mode', Product::STOCK)->where('stock', '<', 8)->update(['stock' => 20]);
        DB::table('product_variants')->whereNotNull('stock')->where('stock', '<', 5)->update(['stock' => 12]);
    }

    private function at(Carbon $day, int $hour): void
    {
        Carbon::setTestNow($day->copy()->setTime($hour, mt_rand(0, 59), mt_rand(0, 59)));
    }

    private function staff(): void
    {
        $this->admin = $this->staffUser('super_admin', 'Hanna', 'Girma');
        foreach (['manager' => ['Yonas', 'Alemu'], 'finance' => ['Meron', 'Tadesse'], 'fulfilment' => ['Kebede', 'Wolde'], 'support' => ['Liya', 'Mekonnen']] as $role => [$f, $l]) {
            $this->staffUser($role, $f, $l);
        }
    }

    private function staffUser(string $role, string $first, string $last): User
    {
        $u = User::query()->create(['first_name' => $first, 'last_name' => $last, 'email' => "demo-$role@kulmis.test",
            'phone' => $this->phone(), 'password' => $this->hash, 'role' => $role, 'email_verified_at' => now()]);

        return $this->named["staff:$role"] = $u;
    }

    private function phone(): string
    {
        return '+2519001'.str_pad((string) (++$this->phoneSeq), 5, '0', STR_PAD_LEFT);
    }

    private function person(string $first, string $last, ?User $referrer, ?string $key = null): User
    {
        $u = User::query()->create([
            'first_name' => $first, 'last_name' => $last, 'phone' => $this->phone(),
            'email' => $key ? "demo-$key@kulmis.test" : Str::slug($first.'.'.$last.'.'.$this->phoneSeq, '.').'@kulmis.test',
            'password' => $this->hash, 'role' => 'customer', 'referrer_id' => $referrer?->id, 'referred_at' => $referrer ? now() : null,
        ]);
        app(ReferralCodes::class)->assign($u);
        if ($key) {
            $this->named[$key] = $u->fresh();
        }

        return $u->fresh();
    }

    /** @return array<int, list<array{0:?string,1:string,2:string,3:?string,4:bool}>> */
    private function signupPlan(): array
    {
        $first = ['Abel', 'Bethel', 'Dawit', 'Eden', 'Fikru', 'Genet', 'Hiwot', 'Ibrahim', 'Kalkidan', 'Lidya', 'Mahlet', 'Nahom', 'Rahel', 'Samuel',
            'Tigist', 'Yared', 'Zewdu', 'Saron', 'Henok', 'Martha', 'Biniam', 'Feven', 'Kidist', 'Natnael', 'Ruth', 'Tewodros', 'Yohannes', 'Aster',
            'Bereket', 'Elsa', 'Girum', 'Helen', 'Kaleb', 'Meseret', 'Robel', 'Selamawit', 'Tsion', 'Yonatan', 'Hirut', 'Mulugeta', 'Sara', 'Eyob'];
        $last = ['Tesfaye', 'Bekele', 'Haile', 'Assefa', 'Getachew', 'Mengistu', 'Negash', 'Abebe', 'Desta', 'Wondimu', 'Gebre', 'Lemma',
            'Tefera', 'Shiferaw', 'Kassa', 'Ayele', 'Berhanu', 'Demissie', 'Fikre', 'Yilma'];
        $n = fn (int $i) => [$first[$i % count($first)], $last[($i * 7) % count($last)]];
        $plan = [];
        $add = function (int $day, ?string $key, array $name, ?string $ref, bool $pays = true) use (&$plan) {
            $plan[min($this->days, max(0, $day))][] = [$key, $name[0], $name[1], $ref, $pays];
        };

        $add(0, 'member', ['Selam', 'Tesfaye'], null);            // the star: big team
        $i = 0;
        for ($k = 1; $k <= 30; $k++) {                              // level 1: 30 people, most pay
            $day = (int) round($k * 5.4) + mt_rand(-2, 2);
            $add($day, "l1-$k", $n($i++), 'member', $k % 7 !== 0);
            if ($k % 3 === 0) {                                      // level 2 under every 3rd
                for ($j = 1; $j <= 2 + intdiv($k, 9); $j++) {
                    $add($day + 6 + $j * 4, "l2-$k-$j", $n($i++), "l1-$k", $j !== 3);
                    if ($j === 1 && $k % 2 === 0) {                  // level 3
                        $add($day + 16, "l3-$k", $n($i++), "l2-$k-$j", true);
                        $add($day + 24, null, $n($i++), "l2-$k-$j", false);
                    }
                }
            }
        }
        foreach (['Abebe' => 'Kebede', 'Tirunesh' => 'Dibaba', 'Mekdes' => 'Alemu'] as $f => $l) {   // other small teams
            $root = Str::slug($f);
            $d = mt_rand(10, 80);
            $add($d, "team-$root", [$f, $l], null);
            for ($k = 1; $k <= 4; $k++) {
                $add($d + $k * 9, null, $n($i++), "team-$root", $k !== 4);
            }
        }
        for ($k = 0; $k < 8; $k++) {                                // shoppers with an account, no membership
            $add(mt_rand(5, 170), "shopper-$k", $n($i++), null, false);
        }
        $add($this->days, 'new', ['Dawit', 'Haile'], null);        // brand-new member today: empty states
        $add($this->days - 9, null, ['Lensa', 'Abdi'], 'member', false); // joined recently, not paid yet: "not active"

        return $plan;
    }

    private function payMembership(User $user, bool $review = false): ?Payment
    {
        try {
            $purchase = app(MembershipService::class)->newPurchase($user->fresh());
            $payment = app(PaymentService::class)->submitProof($purchase, mt_rand(0, 1) ? Payment::TELEBIRR : Payment::BANK, $this->proof(), 'FT'.mt_rand(100000, 999999), null);
            if (! $review) {
                app(PaymentService::class)->approveProof($payment, $this->admin->id);
            }

            return $payment;
        } catch (Throwable $e) {
            $this->command?->warn('Membership skipped: '.$e->getMessage());

            return null;
        }
    }

    /** Members renew around their renewal date; some stop and fall into grace or expire. */
    private function renewals(int $day): void
    {
        foreach ($this->members as [$user, $joined]) {
            $m = Membership::query()->where('user_id', $user->id)->first();
            if (! $m || ! $m->current_period_end) {
                continue;
            }
            $daysLeft = (int) floor(now()->diffInDays($m->current_period_end, false));
            if ($daysLeft > 1 || $daysLeft < -2 || $user->is($this->named['new'] ?? null)) {
                continue;
            }
            $decided = "renew:{$user->id}:".$m->current_period_end->format('Ymd');
            if (isset($this->decided[$decided])) {
                continue;
            }
            $this->decided[$decided] = true;
            $loyal = in_array($user->id, array_filter([$this->named['member']->id ?? null, ($this->named['l1-1'] ?? null)?->id, ($this->named['l1-2'] ?? null)?->id]), true);
            // About 1 in 9 stop renewing each month (never the long-time loyal ones).
            if (! $loyal && (($user->id * 7 + intdiv($day, 30)) % 9 === 0)) {
                continue;
            }
            $this->payMembership($user);
        }
    }

    private function ordersFor(int $day): void
    {
        $count = $day % 7 === 5 ? 3 : (mt_rand(0, 9) < 6 ? 1 : 0); // busier on Saturdays
        if ($day > $this->days - 12) {
            $count += 1 + $day % 2; // a busy last two weeks: orders in every status
        }
        for ($o = 0; $o < $count; $o++) {
            $buyer = null;
            $roll = mt_rand(1, 100);
            if ($roll <= 40 && $this->members !== []) {
                $buyer = $this->members[array_rand($this->members)][0]->fresh();
            } elseif ($roll <= 48) {
                $shoppers = array_values(array_filter($this->named, fn ($k) => str_starts_with((string) $k, 'shopper-'), ARRAY_FILTER_USE_KEY));
                $buyer = $shoppers ? $shoppers[array_rand($shoppers)] : null;
            }
            $this->placeOrder($buyer, $day);
        }
    }

    private function placeOrder(?User $buyer, int $day): void
    {
        try {
            $cart = Cart::query()->create(['token' => Str::random(40), 'user_id' => $buyer?->id]);
            $lines = mt_rand(1, 10) <= 7 ? 1 : 2;
            for ($l = 0; $l < $lines; $l++) {
                $p = $this->buyable[array_rand($this->buyable)];
                $variants = $p->variants()->where('is_active', true)->orderBy('id')->get();
                $variant = $variants->isEmpty() ? null : $variants[mt_rand(0, $variants->count() - 1)]; // seeded, same on every database
                app(CartManager::class)->add($cart, $p->id, $variant?->id, mt_rand(1, 10) <= 8 ? 1 : 2);
            }
            if (mt_rand(1, 10) === 1) {
                $cart->update(['coupon_code' => $buyer?->isActiveMember() ? 'MEMBERS200' : 'COFFEE5']);
            }
            $extra = [];
            $guest = ['Mulu', 'Alem', 'Senait', 'Kebede', 'Wubet', 'Almaz', 'Getu', 'Seble', 'Tsehay', 'Bisrat'];
            if (! $buyer && mt_rand(1, 10) <= 4 && $this->members !== []) {   // a guest buying through a member's link
                $ref = $this->members[array_rand($this->members)][0];
                $extra = ['referrer_user_id' => $ref->id, 'referral_code' => $ref->referral_code];
            }
            $city = ['Addis Ababa', 'Addis Ababa', 'Addis Ababa', 'Adama', 'Hawassa', 'Bahir Dar', 'Dire Dawa', 'Gondar', 'Jimma', 'Mekelle'][mt_rand(0, 9)];
            $order = app(Checkout::class)->place($cart, [
                'first_name' => $buyer->first_name ?? $guest[mt_rand(0, 9)], 'last_name' => $buyer->last_name ?? 'Demo',
                'phone' => $buyer->phone ?? $this->phone(), 'email' => $buyer?->email, 'city' => $city,
                'address' => ['Bole, near Edna Mall', 'Piassa, behind the post office', 'Kebele 04, house 112', 'CMC road', 'Main street'][mt_rand(0, 4)],
            ], $buyer, (string) Str::uuid(), $extra);

            $roll = mt_rand(1, 100);
            if (++$this->placed === 3) {
                return; // always at least one never-paid order (auto-cancelled later)
            }
            if ($day >= $this->days - 1 && $roll <= 35) {
                return; // waiting for payment
            }
            if ($day >= $this->days - 2 && $roll <= 55) {
                app(PaymentService::class)->submitProof($order, Payment::TELEBIRR, $this->proof(), 'TB'.mt_rand(100000, 999999), '0900'.mt_rand(100000, 999999));

                return; // receipt waiting for review
            }
            if ($roll <= 4) {
                return; // never paid: auto-cancelled later
            }
            $payment = app(PaymentService::class)->submitProof($order, mt_rand(0, 1) ? Payment::TELEBIRR : Payment::BANK, $this->proof(), 'FT'.mt_rand(100000, 999999), null);
            if ($roll === 5) {
                app(PaymentService::class)->rejectProof($payment, $this->admin->id, 'The receipt does not show the amount.');

                return;
            }
            app(PaymentService::class)->approveProof($payment, $this->admin->id);
        } catch (Throwable $e) {
            $this->command?->warn('Order skipped: '.$e->getMessage());
        }
    }

    /** Paid orders move on: making → shipped → delivered, a few get refunded. */
    private function progressOrders(): void
    {
        $orders = app(OrderService::class);
        $courier = Courier::query()->where('is_active', true)->orderBy('id')->first();
        foreach (Order::query()->whereIn('status', [Order::PAID, Order::MAKING, Order::SHIPPED])->get() as $order) {
            $age = (int) floor(($order->paid_at ?? $order->created_at)->diffInDays(now()));
            try {
                if ($order->status === Order::PAID && $age >= 2) {
                    $hasMade = $order->items()->where('made_to_order', true)->exists();
                    if ($hasMade) {
                        $orders->transition($order, Order::MAKING, null, $this->admin->id);
                    } elseif ($courier) {
                        $order->update(['courier_id' => $courier->id, 'tracking_number' => 'KM'.mt_rand(10000, 99999)]);
                        $orders->transition($order, Order::SHIPPED, null, $this->admin->id);
                    }
                } elseif ($order->status === Order::MAKING && $age >= 9 && $courier) {
                    $order->update(['courier_id' => $courier->id, 'tracking_number' => 'KM'.mt_rand(10000, 99999)]);
                    $orders->transition($order, Order::SHIPPED, null, $this->admin->id);
                } elseif ($order->status === Order::SHIPPED && $order->shipped_at && $order->shipped_at->diffInDays(now()) >= 3 + $order->id % 3) {
                    $orders->transition($order, Order::DELIVERED, null, $this->admin->id);
                    if ($order->id % 29 === 0) {
                        $payment = Payment::query()->where('payable_type', $order->getMorphClass())->where('payable_id', $order->id)->where('status', Payment::PAID)->first();
                        if ($payment) {
                            app(PaymentService::class)->refund($payment, $payment->amount, 'Arrived broken; full refund.', $this->admin->id);
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->command?->warn('Order step skipped: '.$e->getMessage());
            }
        }
    }

    private function cancelStaleOrders(): void
    {
        foreach (Order::query()->where('status', Order::PENDING_PAYMENT)->where('created_at', '<', now()->subHours(48))->get() as $order) {
            if (Payment::query()->where('payable_type', $order->getMorphClass())->where('payable_id', $order->id)->where('status', 'awaiting_review')->exists()) {
                continue;
            }
            app(OrderService::class)->cancel($order, 'Not paid within 48 hours');
        }
    }

    /** Cash-outs in every status, fraud checks, guest signal buyers. */
    private function moneyEvents(int $day): void
    {
        $star = $this->named['member'] ?? null;
        $cash = app(CashOuts::class);
        try {
            if ($star && $day === 25) {
                app(PayoutAccounts::class)->save($star, ['method' => 'telebirr', 'account_name' => $star->name, 'account_number' => '0900111222'], self::PASSWORD);
            }
            if ($star && in_array($day, [40, 70, 100, 130, 160], true)) {
                $avail = Wallet::for($star)->available();
                if ($avail >= 100_000) {
                    $req = $cash->request($star->fresh(), min($avail, 300_000 + 50_000 * ($day % 3)), 'demo-'.$day);
                    if ($req->status === 'requested' || $req->status === 'on_hold') {
                        $this->at(now()->addDay(), 10);
                        $cash->markPaid($req->fresh(), 'TB-'.mt_rand(1000000, 9999999), $this->admin->id, true);
                    }
                }
            }
            if ($star && $day === $this->days - 1) {                 // waiting for you
                $avail = Wallet::for($star)->available();
                if ($avail >= 100_000) {
                    $cash->request($star->fresh(), min($avail, 150_000), 'demo-last');
                }
            }
            // Other members cash out too: paid, rejected (money back), waiting, and held for review.
            if (in_array($day, [95, 120, 150, $this->days - 4], true)) {
                $earner = $this->topEarner([$star?->id]);
                if ($earner) {
                    $acct = app(PayoutAccounts::class)->current($earner);
                    if (! $acct) {
                        app(PayoutAccounts::class)->save($earner, $day % 2 ? ['method' => 'telebirr', 'account_name' => $earner->name, 'account_number' => '0900'.mt_rand(100000, 999999)]
                            : ['method' => 'bank', 'account_name' => $earner->name, 'account_number' => '1000'.mt_rand(10000000, 99999999), 'bank_name' => 'Commercial Bank of Ethiopia'], self::PASSWORD);
                    }
                    $this->pendingCashOut[$day + 3] = [$earner->id, match ($day) {
                        95 => 'paid', 120 => 'reject', 150 => 'paid', default => 'leave'
                    }];
                }
            }
            if (isset($this->pendingCashOut[$day])) {
                [$uid, $what] = $this->pendingCashOut[$day];
                $u = User::query()->findOrFail($uid);
                $amount = min(Wallet::for($u)->available(), 120_000);
                if ($amount >= 100_000) {
                    $req = $cash->request($u, $amount, "demo-$day-$uid");
                    if ($what === 'paid') {
                        $cash->markPaid($req->fresh(), 'TB-'.mt_rand(1000000, 9999999), $this->admin->id, true);
                    } elseif ($what === 'reject') {
                        $cash->reject($req->fresh(), 'The bank says the account name is different. Please check it.', $this->admin->id);
                    }
                }
            }
            if ($day % 23 === 7) {                                  // a guest buys signals
                $sub = app(SignalAccess::class)->newGuestPurchase(['first_name' => ['Ermias', 'Hanna', 'Solomon', 'Bezawit', 'Amanuel'][$day % 5], 'last_name' => 'Demo', 'phone' => $this->phone()]);
                $payment = app(PaymentService::class)->submitProof($sub, Payment::TELEBIRR, $this->proof(), 'TB'.mt_rand(100000, 999999), null);
                app(PaymentService::class)->approveProof($payment, $this->admin->id);
            }
        } catch (Throwable $e) {
            $this->command?->warn("Money event (day $day) skipped: ".$e->getMessage());
        }
    }

    /** @param list<int|null> $except */
    private function topEarner(array $except): ?User
    {
        $best = null;
        $max = 0;
        foreach ($this->members as [$u]) {
            if (in_array($u->id, $except, true) || PayoutRequest::query()->where('user_id', $u->id)->exists()) {
                continue;
            }
            $avail = Wallet::for($u)->available();
            if ($avail > $max) {
                [$best, $max] = [$u, $avail];
            }
        }

        return $max >= 100_000 ? $best->fresh() : null;
    }

    private function signalsHistory(Carbon $start): void
    {
        $pairs = [['XAU/USD', 2310.5, 9.5], ['EUR/USD', 1.0842, 0.0035], ['GBP/USD', 1.2671, 0.0040], ['BTC/USD', 64250, 1200], ['USD/JPY', 151.32, 0.65], ['ETH/USD', 3120, 85]];
        $day = 0;
        $k = 0;
        while ($day < $this->days) {
            $this->at($start->copy()->addDays($day), 7);
            [$pair, $price, $step] = $pairs[$k % count($pairs)];
            $price *= 1 + (mt_rand(-40, 40) / 1000);
            $buy = $k % 3 !== 1;
            $fmt = fn ($v) => (string) round($v, $price < 10 ? 4 : 1);
            $s = Signal::query()->create([
                'pair' => $pair, 'direction' => $buy ? 'buy' : 'sell', 'entry' => $fmt($price),
                'targets' => [$fmt($price + ($buy ? 1 : -1) * $step), $fmt($price + ($buy ? 2 : -2) * $step)],
                'stop_loss' => $fmt($price - ($buy ? 1 : -1) * $step), 'notes' => 'Wait for the candle to close before entering.',
                'status' => 'open', 'created_by' => $this->admin->id, 'posted_at' => now(),
            ]);
            if ($day < $this->days - 3) {
                $roll = mt_rand(1, 100);
                $result = $roll <= 62 ? 'win' : ($roll <= 90 ? 'loss' : ($roll <= 97 ? 'breakeven' : 'cancelled'));
                $this->at($start->copy()->addDays($day + 1), 16);
                $s->update(['status' => 'closed', 'result' => $result, 'closed_at' => now(), 'locked_at' => now(),
                    'result_value' => match ($result) {
                        'win' => '+'.mt_rand(18, 95).' pips', 'loss' => '-'.mt_rand(15, 40).' pips', default => '0'
                    }]);
            }
            $day += mt_rand(1, 3);
            $k++;
        }
    }

    private function finishingTouches(): void
    {
        $this->demoReviews();
        // A few products end up low or sold out (stock alerts on the dashboard).
        foreach ($this->finalStock as $id => $stock) {
            Product::query()->whereKey($id)->update(['stock' => $stock]);
        }
        DB::table('product_variants')->where('name', 'Blue & silver')->update(['stock' => 1]);
        // Telegram: most members have joined the private channel.
        foreach (SignalSubscription::query()->get() as $i => $sub) {
            if ($i % 5 !== 0 && $sub->status !== 'ended') {
                $sub->forceFill(['telegram_user_id' => 700000000 + $sub->id, 'joined_at' => $sub->created_at->copy()->addHours(2)])->save();
            }
        }
        // Fraud queue: something to review, and decisions from the past.
        $guard = app(FraudGuard::class);
        $pick = fn (string $k) => $this->named[$k] ?? null;
        if ($u = $pick('l1-9')) {
            $guard->flag($u, 'same_browser_signup', ['new_user' => $pick('l2-9-1')?->id, 'note' => 'Sister signed up on the same phone']);
        }
        if (($a = $pick('l1-12')) && ($b = $pick('l1-15'))) {
            $flag = $guard->flag($a, 'shared_payout_account', ['other_user' => $b->id]);
            $guard->review($flag, 'cleared', $this->admin->id, 'Husband and wife, checked by phone.');
        }
        if ($u = $pick('team-abebe')) {
            $guard->flag($u, 'same_network_signups', ['count' => 11]);
        }
        if ($u = $pick('l1-21')) {
            $flag = $guard->flag($u, 'daily_signup_limit', ['count' => 24]);
            $guard->review($flag, 'confirmed', $this->admin->id, 'Fake accounts from one phone shop.');
        }
        // Badges, including past months' top referrers.
        $badges = app(BadgeEngine::class);
        for ($m = 6; $m >= 1; $m--) {
            $badges->awardTopReferrers(now()->subMonthsNoOverflow($m));
        }
        User::query()->where('role', 'customer')->each(fn (User $u) => $badges->evaluate($u));
        // The star member has seen most of her badges; one is new (celebration on sign-in).
        DB::table('user_badges')->where('user_id', $this->named['member']->id)->update(['seen_at' => now()]);
        DB::table('user_badges')->where('user_id', $this->named['member']->id)->orderByDesc('id')->limit(1)->update(['seen_at' => null]);
        // Something in every queue: a receipt to check, a member in grace, a cash-out on hold.
        $shopper = $this->named['shopper-0'] ?? null;
        $this->at(now()->subHours(3), (int) now()->format('G'));
        $cart = Cart::query()->create(['token' => Str::random(40), 'user_id' => $shopper?->id]);
        app(CartManager::class)->add($cart, $this->buyable[0]->id, $this->buyable[0]->variants()->value('id'), 1);
        $order = app(Checkout::class)->place($cart, ['first_name' => $shopper->first_name ?? 'Mulu', 'last_name' => $shopper->last_name ?? 'Demo',
            'phone' => $shopper->phone ?? $this->phone(), 'city' => 'Addis Ababa', 'address' => 'Bole, near Edna Mall'], $shopper, (string) Str::uuid());
        app(PaymentService::class)->submitProof($order, Payment::TELEBIRR, $this->proof(), 'TB'.mt_rand(100000, 999999), '0900'.mt_rand(100000, 999999));
        Carbon::setTestNow();

        $graceUser = collect($this->members)->map(fn ($m) => $m[0])->first(fn (User $u) => ! $u->is($this->named['member']) && ! $u->is($this->named['new'])
            && Membership::query()->where('user_id', $u->id)->where('status', 'active')->exists());
        if ($graceUser) {
            Membership::query()->where('user_id', $graceUser->id)->update(['status' => 'grace', 'current_period_end' => now()->subDay(), 'grace_ends_at' => now()->addDays(2)]);
        }
        PayoutRequest::query()->where('status', 'requested')->where('user_id', '!=', $this->named['member']->id)->limit(1)
            ->update(['status' => 'on_hold', 'hold_reason' => 'First cash-out of a new account: waiting for review']);
        $this->newFeatures();
    }

    /** Something to see in every newer feature: a live flash sale, a bundle, a contest, a challenge, a pre-order, a download, a gift card, a custom order. */
    private function newFeatures(): void
    {
        [$a, $b, $c] = [$this->buyable[1], $this->buyable[2], $this->buyable[3]];
        FlashSale::query()->create(['product_id' => $a->id, 'price' => (int) (round($a->price * 0.8 / 100) * 100),
            'starts_at' => now()->subHours(5), 'ends_at' => now()->addDays(2)->setTime(20, 0), 'stock_limit' => 20, 'is_active' => true])
            ->forceFill(['sold' => 6])->save();
        $bundle = Bundle::query()->create(['name' => 'Coffee ceremony set', 'slug' => 'coffee-ceremony-set', 'description' => 'Everything for a coffee ceremony at home.',
            'price' => (int) (round(($b->price + $c->price) * 0.9 / 100) * 100), 'is_active' => true, 'starts_at' => now()->subDays(3)]);
        foreach ([$b, $c] as $p) {
            $bundle->items()->create(['product_id' => $p->id, 'product_variant_id' => $p->variants()->value('id'), 'quantity' => 1]);
        }
        Contest::query()->create(['name' => 'Month-end team race', 'description' => 'Most new paying members this month wins.',
            'rule' => 'new_paying_members', 'starts_at' => now()->startOfMonth(), 'ends_at' => now()->endOfMonth(),
            'prizes' => [150_000, 100_000, 50_000], 'min_score' => 2, 'status' => 'active']);
        Challenge::query()->create(['name' => 'Bring 5 this month', 'target' => 5, 'reward' => 30_000, 'monthly_cap' => 300_000, 'is_active' => true]);
        // A pre-order (30% now) and a downloadable guide.
        $this->buyable[4]->forceFill(['preorder_deposit_bps' => 3000, 'preorder_ships_on' => now()->addDays(21)->toDateString()])->save();
        $guide = Product::query()->create(['category_id' => $a->category_id, 'name' => 'Coffee ceremony guide (PDF)', 'slug' => 'coffee-ceremony-guide',
            'description' => 'A short guide to the Ethiopian coffee ceremony, step by step. Download it right after payment.',
            'price' => 15_000, 'cost_materials' => 0, 'stock' => 0, 'kind' => 'digital', 'status' => 'active']);
        $path = 'digital/'.$guide->id.'/'.Str::random(32).'.txt';
        Storage::disk('local')->put($path, "Coffee ceremony guide (demo file)\n\n1. Roast the beans.\n2. Grind them.\n3. Brew in the jebena three times.\n");
        ProductFile::query()->create(['product_id' => $guide->id, 'kind' => 'file', 'title' => 'Coffee ceremony guide', 'path' => $path,
            'size' => Storage::disk('local')->size($path), 'position' => 0]);
        // A gift card someone bought, and a custom order waiting for a price.
        $cards = app(GiftCards::class);
        $card = $cards->create(['amount' => GiftCards::amounts()[0] ?? 50_000, 'buyer_name' => 'Hanna', 'buyer_phone' => $this->phone(),
            'recipient_name' => 'Meron', 'message' => 'Happy birthday!']);
        $cards->activate($card);
        $shopper = $this->named['shopper-0'] ?? null;
        CustomRequest::query()->create(['user_id' => $shopper?->id, 'name' => $shopper->first_name ?? 'Mulu', 'phone' => $shopper->phone ?? $this->phone(),
            'city' => 'Addis Ababa', 'address' => 'Bole, near Edna Mall', 'description' => 'A large mesob basket in red and gold for a wedding, about 80 cm high.',
            'budget' => 600_000, 'status' => 'new']);
    }

    /** Options from the environment (not config: the seeder also runs when config is cached). */
    private static function flag(string $name): ?string
    {
        $v = getenv($name);

        return $v === false || $v === '' ? null : $v;
    }

    /** Reviews from demo buyers on delivered demo orders (marked demo; hidden when demo data is off). */
    private function demoReviews(): void
    {
        $texts = [
            [5, 'Beautiful work', 'The weaving is tight and the colours are just like the photos. It arrived well packed.'],
            [5, 'Perfect gift', 'I bought it for my mother and she loves it. You can see it is made by hand.'],
            [4, 'Very nice', 'Good quality and fast delivery. A little smaller than I thought, but very pretty.'],
            [5, null, 'Second time I order here. Always the same good quality, thank you!'],
            [4, 'Good value', 'Strong and well made. The member price makes it a very good deal.'],
        ];
        $i = 0;
        $orders = Order::query()->where('status', Order::DELIVERED)->whereNotNull('user_id')->with('items')->orderBy('id')->limit(40)->get();
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                if (! $item->product_id || ProductReview::query()->where('product_id', $item->product_id)->where('user_id', $order->user_id)->exists()) {
                    continue;
                }
                [$rating, $title, $body] = $texts[$i % count($texts)];
                ProductReview::query()->create(['product_id' => $item->product_id, 'user_id' => $order->user_id, 'order_id' => $order->id,
                    'rating' => $rating, 'title' => $title, 'body' => $body, 'status' => ProductReview::APPROVED,
                    'reviewed_at' => $order->delivered_at, 'is_demo' => true, 'created_at' => $order->delivered_at, 'updated_at' => $order->delivered_at]);
                if (++$i >= 18) {
                    return;
                }
            }
        }
    }

    private function tinyPhoto(int $i): string
    {
        $img = imagecreatetruecolor(64, 64);
        imagefill($img, 0, 0, imagecolorallocate($img, 120 + $i * 3 % 120, 80, 40));
        $path = tempnam(sys_get_temp_dir(), 'kmphoto').'.jpg';
        imagejpeg($img, $path, 70);

        return $path;
    }

    private function proof(): UploadedFile
    {
        $img = imagecreatetruecolor(320, 480);
        imagefill($img, 0, 0, imagecolorallocate($img, 245, 245, 245));
        imagestring($img, 5, 20, 40, 'DEMO RECEIPT', imagecolorallocate($img, 40, 40, 40));
        imagestring($img, 3, 20, 80, 'Telebirr / CBE transfer', imagecolorallocate($img, 80, 80, 80));
        $path = tempnam(sys_get_temp_dir(), 'kmproof').'.jpg';
        imagejpeg($img, $path, 70);

        return new UploadedFile($path, 'receipt.jpg', 'image/jpeg', null, true);
    }
}
