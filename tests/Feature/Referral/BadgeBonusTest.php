<?php

namespace Tests\Feature\Referral;

use App\Badges\BonusCalculator;
use App\Badges\BonusRun;
use App\Badges\ProfitGuard;
use App\Fraud\FraudGuard;
use App\Ledger\Account;
use App\Ledger\JournalEntry;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\Badge;
use App\Models\BadgeBonus;
use App\Models\BadgeBonusRun;
use App\Models\MembershipPurchase;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserBadge;
use App\Support\Features;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Monthly badge bonus: who gets it, how much, the safety limits that keep
 * the shop in profit, and that paying twice is impossible.
 * Month under test: October 2026, run on 1 November 2026.
 */
class BadgeBonusTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE = 500_000;

    private Carbon $oct;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-01 07:00:00', 'Africa/Addis_Ababa'));
        $this->oct = Carbon::parse('2026-10-01', 'Africa/Addis_Ababa');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A member paid for every day of October (periods 15 Sep → 15 Oct → 15 Nov, each paid on its first day). */
    private function paidAllMonth(?User $referrer = null, array $attrs = []): User
    {
        $u = User::factory()->create(['referrer_id' => $referrer?->id] + $attrs);
        $this->period($u, '2026-09-15', '2026-10-15');
        $this->period($u, '2026-10-15', '2026-11-15');

        return $u;
    }

    private function period(User $u, string $start, string $end, ?string $paidAt = null, string $status = 'paid', int $amount = self::PRICE): MembershipPurchase
    {
        $tz = 'Africa/Addis_Ababa';

        return MembershipPurchase::query()->create(['user_id' => $u->id, 'amount' => $amount, 'status' => $status,
            'period_start' => Carbon::parse($start, $tz), 'period_end' => Carbon::parse($end, $tz),
            'paid_at' => $paidAt ? Carbon::parse($paidAt, $tz)->addHour() : Carbon::parse($start, $tz), 'is_demo' => (bool) $u->is_demo]);
    }

    /** @return list<User> */
    private function team(User $leader, int $n): array
    {
        return array_map(fn () => $this->paidAllMonth($leader), range(1, $n));
    }

    private function plan(): array
    {
        return app(BonusCalculator::class)->plan($this->oct);
    }

    private function linesFor(User $u): array
    {
        return array_values(array_filter($this->plan()['pools'][0]['lines'] ?? [], fn ($l) => $l['user_id'] === $u->id));
    }

    private function payOctober(): BadgeBonusRun
    {
        return app(BonusRun::class)->pay($this->oct, null);
    }

    // ---- who gets what ----------------------------------------------------

    public function test_the_default_bonus_table(): void
    {
        $this->assertSame([3 => 20_000, 10 => 60_000, 25 => 150_000, 50 => 300_000, 100 => 600_000],
            BonusCalculator::ladder()->pluck('monthly_bonus', 'threshold')->all());
        $this->assertSame(0, (int) Badge::query()->where('rule', '!=', 'active_level1')->sum('monthly_bonus'));
        $this->assertSame(0, (int) Badge::query()->sum('one_time_bonus'), 'one-time bonuses start at 0');
    }

    public function test_highest_badge_only(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 12);

        $lines = $this->linesFor($leader);
        $this->assertCount(1, $lines, 'Bronze and Silver are not added together');
        $this->assertSame('Silver', $lines[0]['badge']);
        $this->assertSame(60_000, $lines[0]['amount']);
        $this->assertSame(12, $lines[0]['team_active']);
    }

    public function test_below_bronze_gets_nothing(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 2);
        $this->assertSame([], $this->linesFor($leader));
    }

    public function test_the_member_must_have_paid_every_day_of_the_month(): void
    {
        // Grace days: the October 15 renewal was only paid on October 17.
        $late = User::factory()->create();
        $this->period($late, '2026-09-15', '2026-10-15');
        $this->period($late, '2026-10-15', '2026-11-15', '2026-10-17');
        $this->team($late, 5);

        // Joined in the middle of the month.
        $new = User::factory()->create();
        $this->period($new, '2026-10-10', '2026-11-10');
        $this->team($new, 5);

        // Expired on October 20 (nothing after).
        $gone = User::factory()->create();
        $this->period($gone, '2026-09-20', '2026-10-20');
        $this->team($gone, 5);

        // Refunded its October month.
        $refunded = User::factory()->create();
        $this->period($refunded, '2026-09-15', '2026-10-15');
        $this->period($refunded, '2026-10-15', '2026-11-15', null, 'refunded');
        $this->team($refunded, 5);

        // Suspended account.
        $suspended = $this->paidAllMonth(null, ['status' => 'suspended']);
        $this->team($suspended, 5);

        foreach ([$late, $new, $gone, $refunded, $suspended] as $u) {
            $this->assertSame([], $this->linesFor($u), "user {$u->id} should get nothing");
        }
    }

    public function test_team_is_counted_at_month_end_and_grace_or_expired_members_do_not_count(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 2); // 2 paid at month end

        $expired = User::factory()->create(['referrer_id' => $leader->id]);
        $this->period($expired, '2026-09-25', '2026-10-25'); // ended before month end

        $grace = User::factory()->create(['referrer_id' => $leader->id]);
        $this->period($grace, '2026-10-01', '2026-10-31'); // in grace at month end …
        $this->period($grace, '2026-10-31', '2026-11-30', '2026-11-02'); // … paid late, in November

        $this->assertSame([], $this->linesFor($leader), 'only 2 count → no Bronze');

        $joinedLate = User::factory()->create(['referrer_id' => $leader->id]);
        $this->period($joinedLate, '2026-10-30', '2026-11-30'); // joined on the 30th: counts at month end
        $this->assertSame('Bronze', $this->linesFor($leader)[0]['badge'] ?? null);
    }

    public function test_badges_can_go_down(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 4);
        // Unlocked Silver back in the summer, but has 4 active members now.
        UserBadge::query()->create(['user_id' => $leader->id, 'badge_id' => Badge::query()->where('key', 'silver')->value('id'), 'unlocked_at' => now()->subMonths(4)]);

        $this->assertSame('Bronze', $this->linesFor($leader)[0]['badge']);
        $this->assertSame(20_000, $this->linesFor($leader)[0]['amount']);
    }

    public function test_switched_off_badge_does_not_pay_and_the_next_lower_one_does(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 10);
        Features::set('badge.silver', false);
        $this->assertSame('Bronze', $this->linesFor($leader)[0]['badge']);
    }

    // ---- profit limits ----------------------------------------------------

    public function test_per_member_limit_is_two_percent_of_the_teams_real_money(): void
    {
        $leader = $this->paidAllMonth();
        // 3 active members, but their October months were free (0 money): no real money, no bonus.
        foreach (range(1, 3) as $i) {
            $u = User::factory()->create(['referrer_id' => $leader->id]);
            $this->period($u, '2026-10-05', '2026-11-05', null, 'paid', 0);
        }
        $line = $this->linesFor($leader)[0];
        $this->assertSame(0, $line['amount']);
        $this->assertSame('team', $line['limited_by']);

        // Normal price: 3 × 5,000 = 15,000 ETB → limit 300 ETB, Bronze 200 ETB fits.
        $leader2 = $this->paidAllMonth();
        $this->team($leader2, 3);
        $line2 = $this->linesFor($leader2)[0];
        $this->assertSame(20_000, $line2['amount']);
        $this->assertNull($line2['limited_by']);
        $this->assertSame(3 * self::PRICE, $line2['team_money']);
    }

    public function test_a_part_refund_lowers_the_team_money(): void
    {
        $leader = $this->paidAllMonth();
        $team = $this->team($leader, 3);
        $p = MembershipPurchase::query()->where('user_id', $team[0]->id)->where('period_start', '>=', '2026-10-01')->firstOrFail();
        Payment::query()->create(['uuid' => (string) Str::uuid(), 'payable_type' => $p->getMorphClass(), 'payable_id' => $p->id,
            'user_id' => $team[0]->id, 'provider' => 'bank', 'tx_ref' => 'KM-'.Str::random(10), 'amount' => self::PRICE,
            'refunded_amount' => 200_000, 'status' => 'partially_refunded', 'paid_at' => now()]);
        $this->assertSame(3 * self::PRICE - 200_000, $this->linesFor($leader)[0]['team_money']);
    }

    public function test_shop_budget_cuts_everyone_by_the_same_share(): void
    {
        Settings::set('bonus.budget', 100); // 1% of membership money
        $a = $this->paidAllMonth();
        $this->team($a, 3);
        $b = $this->paidAllMonth();
        $this->team($b, 10);

        $pool = $this->plan()['pools'][0];
        $revenue = MembershipPurchase::query()->where('paid_at', '>=', '2026-10-01')->sum('amount');
        $this->assertSame((int) $revenue, $pool['revenue']);
        $this->assertSame(intdiv($pool['revenue'], 100), $pool['budget']);
        $this->assertTrue($pool['adjusted']);
        $this->assertLessThanOrEqual($pool['budget'], $pool['paid']);
        $this->assertSame(80_000, $pool['wanted']);
        $byUser = collect($pool['lines'])->keyBy('user_id');
        // Same share: 200 and 600 cut in the same proportion.
        $this->assertEqualsWithDelta(20_000 / 60_000, $byUser[$a->id]['amount'] / $byUser[$b->id]['amount'], 0.001);
        $this->assertSame('budget', $byUser[$a->id]['limited_by']);

        $run = $this->payOctober();
        $this->assertTrue($run->adjusted);
        $this->actingAs($a)->get('/account')->assertOk()->assertSee('Bonus adjusted this month');
    }

    public function test_the_default_numbers_never_hit_the_budget(): void
    {
        $a = $this->paidAllMonth();
        $this->team($a, 3);
        $pool = $this->plan()['pools'][0];
        $this->assertFalse($pool['adjusted']);
        $this->assertLessThan($pool['budget'], $pool['paid']);
    }

    // ---- paying -----------------------------------------------------------

    public function test_paid_as_on_the_way_for_14_days_then_ready(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 3);
        $run = $this->payOctober();

        $this->assertSame(20_000, $run->paid);
        $this->assertSame(1, $run->members);
        $bonus = BadgeBonus::query()->where('user_id', $leader->id)->firstOrFail();
        $this->assertSame(BadgeBonus::PENDING, $bonus->status);
        $this->assertTrue($bonus->available_at->equalTo(now()->addDays(14)));
        $this->assertSame(['pending' => 20_000, 'available' => 0, 'payout' => 0], Wallet::for($leader)->summary());
        $this->assertSame('badge.bonus', JournalEntry::query()->findOrFail($bonus->journal_entry_id)->type);
        $this->assertSame(20_000, app(Ledger::class)->balance(Account::BADGE_BONUSES));

        $this->assertSame(0, app(BonusRun::class)->releaseDue(), 'not before 14 days');
        Carbon::setTestNow(now()->addDays(14)->addMinute());
        $this->assertSame(1, app(BonusRun::class)->releaseDue());
        $this->assertSame(['pending' => 0, 'available' => 20_000, 'payout' => 0], Wallet::for($leader)->summary());
        $this->assertSame(BadgeBonus::AVAILABLE, $bonus->fresh()->status);
    }

    public function test_running_again_never_pays_twice(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 3);
        $first = $this->payOctober();
        $second = $this->payOctober();
        Artisan::call('badges:bonus'); // the scheduled job, same month

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BadgeBonusRun::query()->count());
        $this->assertSame(1, BadgeBonus::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('type', 'badge.bonus')->count());
        $this->assertSame(20_000, Wallet::for($leader)->pending());
    }

    public function test_only_finished_months_can_be_paid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(BonusRun::class)->pay(now()->startOfMonth(), null);
    }

    public function test_the_first_month_waits_for_the_super_admin_then_it_is_automatic(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 3);

        $this->assertStringContainsString('Waiting', app(BonusRun::class)->scheduled());
        $this->assertSame(0, BadgeBonusRun::query()->count());

        $this->payOctober(); // by hand, after the dry run
        $this->assertStringContainsString('Already done', app(BonusRun::class)->scheduled());

        // November: paid by itself on 1 December.
        $this->period($leader, '2026-11-15', '2026-12-15');
        foreach (User::query()->where('referrer_id', $leader->id)->get() as $u) {
            $this->period($u, '2026-11-15', '2026-12-15');
        }
        Carbon::setTestNow(Carbon::parse('2026-12-01 06:30', 'Africa/Addis_Ababa'));
        $this->assertStringContainsString('Paid', app(BonusRun::class)->scheduled());
        $this->assertSame(2, BadgeBonus::query()->where('user_id', $leader->id)->count());
    }

    public function test_skip_this_month(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 3);
        app(BonusRun::class)->skip($this->oct, null, 'Not this time');

        $run = $this->payOctober();
        $this->assertSame(BadgeBonusRun::SKIPPED, $run->status);
        $this->assertSame(0, BadgeBonus::query()->count());

        $this->expectException(InvalidArgumentException::class);
        app(BonusRun::class)->skip(now()->addMonth(), null);
    }

    public function test_switched_off_pays_nothing_and_keeps_the_data(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 3);
        Features::set('badges.bonus', false);
        $this->assertStringContainsString('switched off', app(BonusRun::class)->scheduled());
        $this->actingAs($leader)->get('/account')->assertOk()->assertDontSee('Your badge bonus');

        $this->expectException(InvalidArgumentException::class);
        $this->payOctober();
    }

    public function test_a_refund_after_the_run_lowers_the_bonus_before_it_is_ready(): void
    {
        $leader = $this->paidAllMonth();
        $team = $this->team($leader, 3);
        $this->payOctober();

        // One team member's October month is refunded in November: only 2 active at month end now.
        MembershipPurchase::query()->where('user_id', $team[0]->id)->where('period_start', '>=', '2026-10-01')->update(['status' => 'refunded']);
        Carbon::setTestNow(now()->addDays(15));
        app(BonusRun::class)->releaseDue();

        $bonus = BadgeBonus::query()->where('user_id', $leader->id)->firstOrFail();
        $this->assertSame(BadgeBonus::REDUCED, $bonus->status);
        $this->assertSame(20_000, $bonus->reduced_amount);
        $this->assertSame(['pending' => 0, 'available' => 0, 'payout' => 0], Wallet::for($leader)->summary());
        $this->assertSame(0, app(Ledger::class)->balance(Account::BADGE_BONUSES));
    }

    public function test_the_leaders_own_refund_cancels_the_bonus(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 3);
        $this->payOctober();
        MembershipPurchase::query()->where('user_id', $leader->id)->where('period_start', '>=', '2026-10-01')->update(['status' => 'refunded']);
        Carbon::setTestNow(now()->addDays(15));
        app(BonusRun::class)->releaseDue();
        $this->assertSame(0, Wallet::for($leader)->available());
    }

    public function test_fraud_hold_keeps_the_bonus_on_the_way_until_cleared(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 3);
        $this->payOctober();
        $flag = app(FraudGuard::class)->flag($leader, 'same_device');

        Carbon::setTestNow(now()->addDays(15));
        $this->assertSame(0, app(BonusRun::class)->releaseDue());
        $this->assertTrue(BadgeBonus::query()->where('user_id', $leader->id)->value('on_hold'));
        $this->assertSame(0, Wallet::for($leader)->available());

        $admin = User::factory()->superAdmin()->create();
        app(FraudGuard::class)->review($flag, 'cleared', $admin->id, 'ok');
        $this->assertSame(1, app(BonusRun::class)->releaseDue());
        $this->assertSame(20_000, Wallet::for($leader)->available());
    }

    public function test_one_time_bonus_for_other_badges(): void
    {
        $firstOrder = Badge::query()->where('key', 'first_order')->firstOrFail();
        $firstOrder->update(['one_time_bonus' => 5_000]);
        $u = $this->paidAllMonth();
        UserBadge::query()->create(['user_id' => $u->id, 'badge_id' => $firstOrder->id, 'unlocked_at' => Carbon::parse('2026-10-12', 'Africa/Addis_Ababa')]);
        $old = $this->paidAllMonth();
        UserBadge::query()->create(['user_id' => $old->id, 'badge_id' => $firstOrder->id, 'unlocked_at' => Carbon::parse('2026-09-12', 'Africa/Addis_Ababa')]);

        $this->assertSame([['first_order', 5_000, 'one_time']],
            array_map(fn ($l) => [Badge::query()->whereKey($l['badge_id'])->value('key'), $l['amount'], $l['kind']], $this->linesFor($u)));
        $this->assertSame([], $this->linesFor($old), 'unlocked in another month');
    }

    public function test_demo_members_are_paid_from_demo_money_only(): void
    {
        $real = $this->paidAllMonth();
        $this->team($real, 3);
        $demo = $this->paidAllMonth(null, ['is_demo' => true]);
        foreach (range(1, 3) as $i) {
            $this->paidAllMonth($demo, ['is_demo' => true]);
        }
        $plan = $this->plan();
        $this->assertSame([$real->id], array_column($plan['pools'][0]['lines'], 'user_id'));
        $this->assertSame([$demo->id], array_column($plan['pools'][1]['lines'], 'user_id'));
        $this->assertSame(4 * self::PRICE, $plan['pools'][0]['revenue'], 'October money of the 4 real members');
        $this->assertSame(4 * self::PRICE, $plan['pools'][1]['revenue']);
    }

    // ---- settings guard ---------------------------------------------------

    public function test_worst_case_payout_with_the_default_numbers(): void
    {
        $w = ProfitGuard::worstCase();
        $this->assertSame(1_700, $w['commissions']);
        $this->assertSame(134, $w['bonus'], '200 ÷ (3 × 5,000), rounded up');
        $this->assertSame(200, $w['loyalty'], '300 ÷ (3 × 5,000)');
        $this->assertSame(500, $w['first_payment'], '250 ÷ 5,000');
        $this->assertSame(2_534, $w['total']);
        $this->assertSame(373_300, $w['keep'], 'you keep at least 3,733 ETB of 5,000 ETB');
        $this->assertStringContainsString('3,733', ProfitGuard::keepLine());
    }

    public function test_unsafe_numbers_cannot_be_saved(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin);

        $this->putJson('/api/v1/admin/settings', ['settings' => ['referral.rate.membership.l1' => 3_000]])
            ->assertJsonValidationErrors('profit');
        $this->assertSame(1_000, Settings::int('referral.rate.membership.l1'), 'rolled back');
        $this->putJson('/api/v1/admin/settings', ['settings' => ['referral.rate.membership.l1' => 1_200]])->assertOk();

        // A badge bonus far too big for its threshold is still held to the 2% team limit: allowed.
        $bronze = Badge::query()->where('key', 'bronze')->firstOrFail();
        $this->putJson("/api/v1/admin/badges/{$bronze->id}", ['monthly_bonus' => 1_000_000])->assertOk();
        // Monthly bonus only on level-1 team badges.
        $loyal = Badge::query()->where('key', 'loyal_3')->firstOrFail();
        $this->putJson("/api/v1/admin/badges/{$loyal->id}", ['monthly_bonus' => 10_000])->assertJsonValidationErrors('monthly_bonus');
        $this->putJson("/api/v1/admin/badges/{$bronze->id}", ['one_time_bonus' => 10_000])->assertJsonValidationErrors('one_time_bonus');

        // Loyalty so big it breaks the 35%.
        $this->putJson('/api/v1/admin/settings', ['settings' => ['loyalty.reward' => 400_000]])->assertJsonValidationErrors('profit');
        $this->getJson('/api/v1/admin/badge-bonus')->assertOk()->assertJsonPath('data.guard.max', 3_500);
    }

    public function test_admin_page_dry_run_then_pay_now_then_history(): void
    {
        $leader = $this->paidAllMonth();
        $this->team($leader, 10);
        $admin = User::factory()->superAdmin()->create();
        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin);

        $page = $this->getJson('/api/v1/admin/badge-bonus')->assertOk();
        $page->assertJsonPath('data.last_month.month', '2026-10')->assertJsonPath('data.last_month.run', null)
            ->assertJsonPath('data.last_month.preview.paid', 60_000)->assertJsonPath('data.last_month.preview.lines.0.badge', 'Silver')
            ->assertJsonPath('data.first_payout_done', false);
        $this->assertSame(0, BadgeBonus::query()->count(), 'the dry run pays nothing');

        $this->postJson('/api/v1/admin/badge-bonus/pay', ['month' => '2026-11'])->assertJsonValidationErrors('month'); // not finished
        $this->postJson('/api/v1/admin/badge-bonus/pay', ['month' => '2026-10'])->assertOk()->assertJsonPath('data.paid', 60_000);
        $this->postJson('/api/v1/admin/badge-bonus/pay', ['month' => '2026-10'])->assertOk()->assertJsonPath('data.paid', 60_000);
        $this->assertSame(60_000, Wallet::for($leader)->pending());
        $this->getJson('/api/v1/admin/badge-bonus/months/2026-10')->assertOk()->assertJsonPath('data.lines.0.amount', 60_000);
        $this->getJson('/api/v1/admin/badge-bonus')->assertJsonPath('data.history.0.status', 'paid')->assertJsonPath('data.first_payout_done', true);

        $this->postJson('/api/v1/admin/badge-bonus/skip', ['month' => '2026-10'])->assertJsonValidationErrors('month'); // already paid
        $this->postJson('/api/v1/admin/badge-bonus/skip', ['month' => '2026-11', 'note' => 'Holiday'])->assertOk()->assertJsonPath('data.status', 'skipped');

        // Other staff can't see it.
        $finance = User::factory()->role('finance')->create();
        $finance->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($finance)->getJson('/api/v1/admin/badge-bonus')->assertForbidden();
    }

    public function test_member_card_shows_badge_expected_bonus_next_badge_and_history(): void
    {
        $leader = $this->paidAllMonth();
        $team = $this->team($leader, 4);
        $this->payOctober();
        // November: still paid; 4 active now.
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00', 'Africa/Addis_Ababa'));
        $page = $this->actingAs($leader)->get('/account')->assertOk();
        $page->assertSee('Your badge bonus')->assertSee('Bronze')->assertSee('Expected for November')
            ->assertSee('Next: <b>Silver</b>', false)->assertSee('4/10')->assertSee('October 2026')->assertSee('On the way');

        // A member who had unpaid days this month sees why there is no bonus.
        $late = User::factory()->create();
        $this->period($late, '2026-10-01', '2026-11-01');
        $this->period($late, '2026-11-01', '2026-12-01', '2026-11-03');
        $this->actingAs($late)->get('/account')->assertOk()->assertSee('No bonus this month');
        $this->assertCount(4, $team);
    }
}
