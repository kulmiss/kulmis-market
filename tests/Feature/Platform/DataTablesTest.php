<?php

namespace Tests\Feature\Platform;

use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Member\MemberTable;
use App\Models\Commission;
use App\Models\FraudFlag;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The shared data tables: admin lists sort/search on the server; member tables search, filter, sort, page and export. */
class DataTablesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $a = User::factory()->superAdmin()->create();
        $a->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        return $a;
    }

    public function test_admin_lists_sort_by_any_listed_column_and_ignore_others(): void
    {
        foreach (['Cara', 'Abel', 'Bilen'] as $name) {
            User::factory()->create(['first_name' => $name]);
        }
        $this->actingAs($this->admin());

        $names = fn (string $sort) => collect($this->getJson('/api/v1/admin/members?sort='.$sort)->assertOk()->json('data'))->pluck('first_name')->all();
        $this->assertSame(['Abel', 'Bilen', 'Cara'], $names('name'));
        $this->assertSame(['Cara', 'Bilen', 'Abel'], $names('-name'));
        $this->assertSame(['Bilen', 'Abel', 'Cara'], $names('password'), 'unknown columns fall back to newest first');
        $this->getJson('/api/v1/admin/members?per_page=1000')->assertOk()->assertJsonPath('per_page', 200);
    }

    public function test_every_admin_list_answers_search_sort_and_page_size(): void
    {
        $this->actingAs($this->admin());
        foreach ([
            '/members?sort=-joined', '/orders?sort=-total&q=KM', '/products?sort=price', '/payments?sort=-amount&q=x', '/coupons?sort=code&q=A',
            '/fraud?sort=-created&status=all&q=a', '/activity?sort=action&q=a', '/referrals/commissions?sort=-amount&q=a', '/cash-outs?sort=-amount&q=a',
            '/ledger/entries?sort=type&q=a', '/signals?sort=-posted&q=BTC', '/signals/subscribers?sort=name&q=a',
        ] as $uri) {
            $this->getJson('/api/v1/admin'.$uri.'&per_page=10')->assertOk()->assertJsonPath('per_page', 10);
        }
    }

    public function test_fraud_search_finds_the_member(): void
    {
        $u = User::factory()->create(['first_name' => 'Zelalem']);
        FraudFlag::query()->create(['user_id' => $u->id, 'type' => 'same_device', 'status' => 'open', 'details' => []]);
        FraudFlag::query()->create(['user_id' => User::factory()->create()->id, 'type' => 'same_device', 'status' => 'open', 'details' => []]);
        $this->actingAs($this->admin());
        $this->assertCount(1, $this->getJson('/api/v1/admin/fraud?q=Zelal')->assertOk()->json('data'));
    }

    private function earnings(User $me, int $n): void
    {
        $from = User::factory()->create();
        foreach (range(1, $n) as $i) {
            Commission::query()->create(['recipient_id' => $me->id, 'buyer_user_id' => $from->id, 'level' => ($i % 3) + 1, 'type' => $i % 2 ? 'membership' : 'products',
                'base_amount' => 500_000, 'rate_bps' => 1000, 'amount' => $i * 1_000, 'status' => $i % 4 === 0 ? 'available' : 'pending',
                'source_type' => 'membership_purchase', 'source_id' => $i, 'available_at' => now()->addDays(14)]);
        }
    }

    public function test_member_table_filters_sorts_pages_and_remembers_settings_in_the_browser(): void
    {
        $me = User::factory()->member()->create();
        $this->earnings($me, 30);
        $this->actingAs($me);

        $page = $this->get('/account/earnings')->assertOk();
        $page->assertSee('data-mtable="earnings"', false)->assertSee('1–25 of 30')->assertSee('Columns')->assertSee('CSV')
            ->assertSee('sticky top-0', false)->assertSee('md:hidden', false); // sticky header + phone cards

        $this->get('/account/earnings?per_page=10')->assertSee('1–10 of 30')->assertSee('1/3');
        $this->get('/account/earnings?status=available')->assertSee('of 7')->assertSee('Filters (1)');
        $this->get('/account/earnings?level=1&type=products')->assertOk()->assertSee('Filters (2)');
        $sorted = $this->get('/account/earnings?sort=-amount&per_page=10')->assertOk();
        $sorted->assertSeeInOrder([Money::format(30_000), Money::format(29_000), Money::format(28_000)]);
        $this->get('/account/earnings?per_page=7&sort=password')->assertOk()->assertSee('of 30'); // bad values are ignored
    }

    public function test_member_csv_has_every_matching_row_and_is_safe_in_excel(): void
    {
        $me = User::factory()->member()->create();
        $this->earnings($me, 30);
        $this->actingAs($me);

        $res = $this->get('/account/earnings?export=earnings&status=pending');
        $res->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8');
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}Date,From,Level,Amount,Status", $csv);
        $this->assertSame(1 + 23, substr_count(trim($csv), "\n") + 1, 'header + all 23 pending rows, not just one page');
        $this->assertStringContainsString('On the way', $csv);

        $this->assertSame("'=HYPERLINK(1)", MemberTable::safe('=HYPERLINK(1)'));
        $this->assertSame("'@SUM(A1)", MemberTable::safe('@SUM(A1)'));
        $this->assertSame('-150', MemberTable::safe('-150'));
    }

    public function test_team_and_orders_tables(): void
    {
        $me = User::factory()->member()->create();
        User::factory()->create(['referrer_id' => $me->id, 'first_name' => 'Hana', 'last_name' => 'Tesfaye']);
        User::factory()->member()->create(['referrer_id' => $me->id, 'first_name' => 'Dawit', 'last_name' => 'Bekele']);
        Order::query()->create(['number' => 'KM9001', 'user_id' => $me->id, 'status' => 'delivered', 'first_name' => 'A',
            'phone' => '+251911000001', 'city' => 'Addis Ababa', 'address' => 'x', 'list_subtotal' => 100_000, 'merchandise_total' => 100_000, 'total' => 100_000, 'checkout_key' => 'k9']);
        $this->actingAs($me);

        $this->get('/account/team?q=hana')->assertOk()->assertSee('Hana T.')->assertDontSee('Dawit B.');
        $this->get('/account/team?status=active')->assertOk()->assertSee('Dawit B.')->assertDontSee('Hana T.');
        $csv = $this->get('/account/team?export=team')->assertOk()->streamedContent();
        $this->assertStringContainsString('Hana T.', $csv);
        $this->assertStringNotContainsString('Tesfaye', $csv, 'privacy: first name + initial only');

        $this->get('/account/orders')->assertOk()->assertSee('KM9001')->assertSee('Delivered');
        $this->get('/account/orders?q=nope')->assertOk()->assertSee('Nothing matches');
    }

    public function test_wallet_has_money_history_and_cash_outs_with_their_own_settings(): void
    {
        $me = User::factory()->member()->create();
        app(Ledger::class)->post('loyalty.reward', [[Account::LOYALTY, 30_000], [app(Ledger::class)->userAccount($me, Wallet::AVAILABLE), -30_000]], 'test:loyalty', null);
        $this->actingAs($me);

        $page = $this->get('/account/wallet')->assertOk();
        $page->assertSee('Money history')->assertSee('Loyalty reward')->assertSee('Ready to use')->assertSee('data-mtable="cashouts"', false)
            ->assertSee('name="c_status"', false);
        $this->get('/account/wallet?bucket=pending')->assertOk()->assertSee('Nothing matches');
        $this->get('/account/wallet?c_status=paid&bucket=available')->assertOk()->assertSee('Loyalty reward');
        $csv = $this->get('/account/wallet?export=money')->assertOk()->streamedContent();
        $this->assertStringContainsString('Loyalty reward', $csv);
    }
}
