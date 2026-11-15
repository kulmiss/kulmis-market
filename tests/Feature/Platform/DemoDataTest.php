<?php

namespace Tests\Feature\Platform;

use App\Demo\Demo;
use App\Jobs\SendCustomerMessage;
use App\Ledger\Wallet;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Notify\Message;
use App\Notify\Sms\SmsGateway;
use App\Payments\PaymentService;
use App\Reports\Dashboard;
use App\Security\LoginGuard;
use App\Support\Settings;
use App\Support\Totp;
use App\Telegram\TelegramClient;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Demo data: lots of it for every section, hidden everywhere by one switch,
 * never mixed into real money totals, deletable, and impossible on a live shop.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function seedDemo(int $days = 20): void
    {
        putenv("DEMO_DAYS=$days");
        putenv('DEMO_PHOTOS=0'); // tiny photos: fast tests (the real ones are checked separately)
        $this->seed(DemoSeeder::class);
        putenv('DEMO_DAYS');
        putenv('DEMO_PHOTOS');
        Settings::flush();
    }

    private function superAdmin(): User
    {
        $u = User::factory()->superAdmin()->create();
        $u->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($u, 'web');

        return $u;
    }

    private function realOrder(int $price = 250_000): Order
    {
        $p = Product::factory()->create(['price' => $price, 'stock' => 5, 'slug' => 'real-thing-'.Str::random(4)]);
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', [
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Real', 'phone' => '0911223344', 'city' => 'Addis Ababa', 'address' => 'x',
        ])->assertCreated();
        $this->flushHeaders();

        return Order::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_real_photos_are_in_the_repo_with_credits(): void
    {
        $credits = (string) file_get_contents(database_path('seeders/demo-photos/CREDITS.md'));
        preg_match_all("/, \d+, '([a-z0-9-]+)', \[/", (string) file_get_contents(database_path('seeders/DemoSeeder.php')), $photos);
        $this->assertGreaterThanOrEqual(30, count(array_unique($photos[1])));
        foreach (array_unique($photos[1]) as $photo) {
            $this->assertFileExists(database_path("seeders/demo-photos/$photo.jpg"));
            $this->assertStringContainsString("`$photo.jpg`", $credits);
            $this->assertNotFalse(getimagesize(database_path("seeders/demo-photos/$photo.jpg")));
        }
        $this->assertStringContainsString('pexels.com/license', $credits);
    }

    public function test_the_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=production');
        (new DemoSeeder)->run();
    }

    public function test_demo_data_covers_every_section_and_is_all_flagged(): void
    {
        $this->seedDemo();

        $this->assertGreaterThanOrEqual(30, Product::query()->count());
        $this->assertTrue(Product::query()->where('stock_mode', Product::MADE_TO_ORDER)->exists());
        $this->assertTrue(Product::query()->where('is_members_only', true)->exists());
        $this->assertTrue(Product::query()->whereNotNull('release_at')->exists(), 'early access');
        $this->assertTrue(DB::table('product_variants')->exists());
        $this->assertTrue(DB::table('product_images')->count() >= 30, 'every product has a real photo');
        $this->assertGreaterThanOrEqual(5, DB::table('coupons')->count());
        foreach (['users', 'orders', 'payments', 'commissions', 'signals', 'signal_subscriptions', 'activity_log', 'journal_entries', 'user_badges'] as $t) {
            $this->assertGreaterThan(0, DB::table($t)->count(), "$t has demo rows");
            $this->assertSame(0, DB::table($t)->where('is_demo', false)->count(), "every $t row is demo");
        }
        $statuses = DB::table('orders')->pluck('status')->unique()->all();
        foreach (['delivered', 'shipped', 'pending_payment', 'cancelled'] as $s) {
            $this->assertContains($s, $statuses);
        }
        $this->assertContains('active', DB::table('memberships')->pluck('status')->all());
        $this->assertContains('grace', DB::table('memberships')->pluck('status')->all());
        $star = User::query()->where('email', 'demo-member@kulmis.test')->firstOrFail();
        $this->assertGreaterThanOrEqual(25, User::query()->where('referrer_id', $star->id)->count(), 'a deep tree with many level-1 members');
        $this->assertTrue(User::query()->whereIn('referrer_id', User::query()->where('referrer_id', $star->id)->select('id'))->exists(), 'level 2');
        foreach (['super_admin', 'manager', 'finance', 'fulfilment', 'support'] as $role) {
            $this->assertDatabaseHas('users', ['email' => "demo-$role@kulmis.test", 'role' => $role, 'is_demo' => true]);
        }
        $this->assertTrue(DB::table('payments')->where('status', 'awaiting_review')->exists());
        $this->assertTrue(DB::table('fraud_flags')->where('status', 'open')->exists());
        $this->assertTrue(DB::table('signals')->whereNotNull('result')->exists());
        $this->assertTrue(DB::table('signal_subscriptions')->whereNotNull('telegram_user_id')->exists());
        $this->assertGreaterThan(0, Wallet::for($star)->pending() + Wallet::for($star)->available());

        // Running it again adds nothing.
        $users = DB::table('users')->count();
        $this->seed(DemoSeeder::class);
        $this->assertSame($users, DB::table('users')->count());
    }

    public function test_switching_demo_off_hides_it_everywhere_without_deleting(): void
    {
        $this->seedDemo();
        $slug = Product::query()->firstOrFail()->slug;
        $names = Product::query()->pluck('name')->map(fn ($n) => e($n));
        $shows = fn (string $html) => $names->contains(fn ($n) => str_contains($html, $n));
        $this->assertTrue($shows($this->get('/shop')->assertOk()->getContent()));
        $this->get("/shop/$slug")->assertOk();

        Settings::set('demo.show', false);

        $this->assertFalse($shows($this->get('/shop')->assertOk()->getContent()));
        $this->assertFalse($shows($this->get('/')->assertOk()->getContent()), 'home page too');
        $this->get("/shop/$slug")->assertNotFound();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/referral/top')->assertOk()->assertJsonCount(0, 'data');
        $this->post('/login', ['login' => 'demo-member@kulmis.test', 'password' => DemoSeeder::PASSWORD])->assertSessionHasErrors();
        $this->assertSame(0, User::query()->where('is_demo', true)->count());
        $this->assertGreaterThan(0, User::query()->withoutGlobalScopes()->where('is_demo', true)->count(), 'not deleted');

        $this->superAdmin();
        $this->getJson('/api/v1/admin/orders')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/admin/members')->assertOk()->assertJsonPath('total', 0);
        $dash = $this->getJson('/api/v1/admin/dashboard?data=demo')->assertOk()->json('data');
        $this->assertSame('real', $dash['dataset'], 'hidden demo data can not be asked for');
        $this->assertSame(0, $dash['revenue']['total']);

        // And back.
        $this->putJson('/api/v1/admin/demo', ['show' => true])->assertOk()->assertJsonPath('data.visible', true);
        $this->get("/shop/$slug")->assertOk();
    }

    public function test_demo_and_real_money_are_never_mixed(): void
    {
        $this->seedDemo();
        $this->superAdmin();
        $order = $this->realOrder(250_000);
        $pay = app(PaymentService::class);
        $p = $pay->submitProof($order, Payment::BANK, UploadedFile::fake()->image('r.jpg'), 'X', null);
        $pay->approveProof($p, auth()->id());
        $this->assertFalse((bool) $order->fresh()->is_demo);
        $this->assertSame(0, DB::table('ledger_postings')->where('ledger_postings.is_demo', false)->join('journal_entries', 'journal_entries.id', '=', 'ledger_postings.journal_entry_id')->where('journal_entries.is_demo', true)->count());

        $from = now()->subYear();
        $real = app(Dashboard::class)->build($from, now()->endOfDay(), false);
        $demo = app(Dashboard::class)->build($from, now()->endOfDay(), true);
        $this->assertSame(1, $real['orders']['paid'], 'only the real order');
        $this->assertGreaterThan(0, $real['revenue']['products']);
        $this->assertLessThan($demo['revenue']['total'], $real['revenue']['total']);
        $this->assertGreaterThan(20, $demo['orders']['paid']);
        $this->assertSame(0, $real['membership']['active'], 'demo members are not real members');
        $this->getJson('/api/v1/admin/dashboard?data=real')->assertJsonPath('data.orders.paid', 1)->assertJsonPath('data.dataset', 'real');
        $this->getJson('/api/v1/admin/dashboard')->assertJsonPath('data.dataset', 'demo')->assertJsonPath('data.demo.exists', true);
        $accounts = collect($this->getJson('/api/v1/admin/ledger/accounts')->assertOk()->json('data'))->keyBy('code');
        $this->assertArrayHasKey('demo_balance', $accounts->first());
    }

    public function test_what_a_demo_customer_creates_is_demo_and_messages_to_demo_contacts_are_not_sent(): void
    {
        $this->seedDemo();
        $member = User::query()->where('email', 'demo-member@kulmis.test')->firstOrFail();
        $this->actingAs($member, 'sanctum');
        $p = Product::query()->where('stock_mode', Product::STOCK)->where('stock', '>', 3)->where('is_members_only', false)->whereNull('release_at')->whereDoesntHave('variants')->firstOrFail();
        $token = $this->postJson('/api/v1/cart/items', ['product_id' => $p->id])->json('token');
        $this->withHeaders(['X-Cart-Token' => $token])->postJson('/api/v1/checkout', [
            'checkout_key' => (string) Str::uuid(), 'first_name' => 'Selam', 'phone' => '0900100001', 'city' => 'Addis Ababa', 'address' => 'x',
        ])->assertCreated();
        $this->assertTrue((bool) Order::query()->latest('id')->firstOrFail()->is_demo);

        $sms = new class implements SmsGateway
        {
            public int $sent = 0;

            public function send(string $to, string $message): void
            {
                $this->sent++;
            }
        };
        (new SendCustomerMessage(new Message('Hi', 'Body', '+251900100001', 'x@kulmis.test')))->handle($sms, app(TelegramClient::class));
        $this->assertSame(0, $sms->sent);
        (new SendCustomerMessage(new Message('Hi', 'Body', '+251911223344', null)))->handle($sms, app(TelegramClient::class));
        $this->assertSame(1, $sms->sent, 'real people still get messages');
    }

    public function test_demo_accounts_can_not_sign_in_on_a_live_shop(): void
    {
        $this->seedDemo(14);
        $user = User::query()->where('email', 'demo-member@kulmis.test')->firstOrFail();
        $guard = app(LoginGuard::class);
        $this->app['env'] = 'production';
        try {
            $guard->attempt($user, DemoSeeder::PASSWORD, 'web', fn () => true);
            $this->fail('A demo account signed in on a live shop.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertTrue($guard->attempt($user->fresh(), DemoSeeder::PASSWORD, 'web', fn () => true)->is($user));
        $this->post('/login', ['login' => 'demo-member@kulmis.test', 'password' => DemoSeeder::PASSWORD])->assertRedirect('/account');
    }

    public function test_delete_all_demo_data_needs_super_admin_and_the_exact_words_and_keeps_real_data(): void
    {
        $this->seedDemo(14);
        $order = $this->realOrder();
        $realUser = User::factory()->create(['referrer_id' => User::query()->where('email', 'demo-member@kulmis.test')->value('id')]);
        $photos = count(Storage::disk('public')->allFiles());
        $this->assertGreaterThan(0, $photos);

        $manager = User::factory()->role('finance')->create();
        $manager->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($manager, 'web');
        $this->deleteJson('/api/v1/admin/demo', ['confirm' => 'DELETE DEMO DATA'])->assertForbidden();

        $this->superAdmin();
        $this->deleteJson('/api/v1/admin/demo', ['confirm' => 'delete'])->assertJsonValidationErrors('confirm');
        $this->deleteJson('/api/v1/admin/demo', ['confirm' => 'DELETE DEMO DATA'])->assertOk()->assertJsonPath('data.exists', false);

        foreach (Demo::TABLES as $t) {
            $this->assertSame(0, DB::table($t)->where('is_demo', true)->count(), "$t demo rows deleted");
        }
        $this->assertModelExists($order);
        $this->assertNull($realUser->fresh()->referrer_id, 'real person keeps the account, without the demo referrer');
        $this->assertLessThan($photos, count(Storage::disk('public')->allFiles()), 'demo photos removed');
        $this->assertDatabaseHas('activity_log', ['action' => 'demo.deleted']);
        $this->get('/shop/jebena-coffee-pot')->assertNotFound();
    }
}
