<?php

namespace Tests\Feature\Referral;

use App\Badges\BonusCalculator;
use App\Badges\BonusRun;
use App\Badges\ProfitGuard;
use App\Ledger\Account;
use App\Ledger\Ledger;
use App\Models\Badge;
use App\Models\BadgeBonus;
use App\Models\Challenge;
use App\Models\Contest;
use App\Rewards\Challenges;
use App\Rewards\Contests;
use App\Rewards\Rewards;
use App\Rewards\Scores;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Will I be in profit every month?" Thousands of random members and teams
 * (late payers, refunds, free months, people who join or leave in the
 * middle), paid with the real code. For every month we add up, as a worst
 * case, everything that can go out of the membership money:
 *   team earnings on every October membership (all 3 levels, as if every
 *   referrer earned), the badge bonuses really paid, and the loyalty reward
 *   at its worst-case share,
 * and check the shop keeps at least what the settings guard promises.
 */
class BonusProfitSimulationTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Africa/Addis_Ababa';

    /** @return array<string, array{0:int, 1:callable|null}> */
    public static function scenarios(): array
    {
        return [
            'default numbers' => [101, null],
            'generous bonuses (10× the table)' => [202, function () {
                foreach (['bronze', 'silver', 'gold', 'platinum', 'diamond'] as $k) {
                    $b = Badge::query()->where('key', $k)->firstOrFail();
                    $b->update(['monthly_bonus' => $b->monthly_bonus * 10]);
                }
            }],
            'tiny thresholds and one-time bonuses' => [303, function () {
                Badge::query()->where('key', 'bronze')->update(['threshold' => 1, 'monthly_bonus' => 500_000]);
                Badge::query()->whereIn('key', ['first_sale', 'loyal_3', 'team_25'])->update(['one_time_bonus' => 100_000]);
            }],
            'tight budget (0.2%): everyone is cut' => [404, fn () => Settings::set('bonus.budget', 20)],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_the_shop_keeps_its_share_every_month(int $seed, ?callable $setup): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-01 07:00', self::TZ));
        if ($setup) {
            $setup();
        }
        mt_srand($seed);
        $users = $this->randomMembers(2_500);

        $run = app(BonusRun::class)->pay(Carbon::parse('2026-10-01', self::TZ), null);
        $revenue = $run->revenue;

        // October also had a referral contest (generous fixed prizes) and a team challenge.
        $contest = Contest::query()->create(['name' => 'October contest', 'rule' => 'new_paying_members', 'prizes' => [2_000_000, 1_000_000, 500_000, 250_000, 250_000],
            'starts_at' => Carbon::parse('2026-10-01', self::TZ), 'ends_at' => Carbon::parse('2026-11-01', self::TZ)]);
        $challenge = Challenge::query()->create(['name' => 'Bring 3', 'target' => 3, 'reward' => 50_000]);
        Carbon::setTestNow(Carbon::parse('2026-11-16 08:00', self::TZ)); // after the 14-day check
        app(Contests::class)->settle($contest);
        app(Challenges::class)->settle($challenge, Carbon::parse('2026-10-01', self::TZ));
        $contestPaid = Rewards::total('contest');
        $challengePaid = Rewards::total('challenge');
        $octoberMoney = Scores::membershipMoney(Carbon::parse('2026-10-01', self::TZ), Carbon::parse('2026-11-01', self::TZ));
        $this->assertLessThanOrEqual(intdiv($octoberMoney * Settings::int('contest.budget'), 10_000), $contestPaid, 'contest prizes stay inside their budget');
        $this->assertLessThanOrEqual(intdiv($octoberMoney * Settings::int('challenge.budget'), 10_000), $challengePaid, 'challenge rewards stay inside their budget');
        $this->assertGreaterThan(1_000 * 500_000, $revenue, 'the simulation has real money in it');

        // Everything that can go out of October's membership money.
        $commissions = $this->worstCaseCommissions($users);
        $bonuses = app(Ledger::class)->balance(Account::BADGE_BONUSES);
        $guard = ProfitGuard::worstCase();
        $loyalty = intdiv($revenue * $guard['loyalty'], 10_000);
        $newPayouts = $this->worstCaseNewPayouts($users) + $contestPaid + $challengePaid;
        $kept = $revenue - $commissions - $bonuses - $loyalty - $newPayouts;

        if (getenv('SIM_REPORT')) {
            fwrite(STDERR, sprintf("\n%d: money %s, team earnings %s, bonuses %s to %d members (budget %s%s), loyalty %s, new payouts %s → kept %.2f%%\n", $seed,
                number_format($revenue / 100), number_format($commissions / 100), number_format($bonuses / 100), $run->members,
                number_format($run->budget / 100), $run->adjusted ? ', adjusted' : '', number_format($loyalty / 100), number_format($newPayouts / 100), $kept * 100 / $revenue));
        }
        $this->assertSame($run->paid, $bonuses);
        $this->assertLessThanOrEqual($run->budget, $bonuses, 'never more than the monthly budget');
        $this->assertLessThanOrEqual(intdiv($revenue * Settings::int('bonus.budget'), 10_000), $bonuses);
        $this->assertGreaterThanOrEqual($revenue * (10_000 - $guard['total']) / 10_000, $kept, 'the guard\'s promise holds');
        $this->assertGreaterThanOrEqual($revenue * (10_000 - ProfitGuard::MAX_BPS) / 10_000, $kept, 'always at least 65%');
        $this->assertGreaterThanOrEqual(0.75, $kept / $revenue, 'with every new payout on: the shop keeps at least 75%');
        if ($setup === null) {
            $this->assertGreaterThanOrEqual(0.79, $kept / $revenue, 'default numbers: you keep at least 79%');
            $this->assertFalse($run->adjusted, 'the default table never needs the budget cut');
        }
        if ($seed === 404) {
            $this->assertTrue($run->adjusted, 'this one is over the budget');
            $this->assertGreaterThan($run->budget - $run->members, $bonuses, 'the budget is used, not wasted');
        }

        // Per member: never more than 2% of what their own level-1 team paid that month (monthly bonus).
        foreach (BadgeBonus::query()->where('kind', BonusCalculator::MONTHLY)->get() as $b) {
            $this->assertLessThanOrEqual(intdiv($b->team_money * Settings::int('bonus.team_limit'), 10_000), $b->amount);
            $this->assertLessThanOrEqual($b->wanted, $b->amount);
        }
        // Highest badge only: one monthly bonus per member.
        $monthly = BadgeBonus::query()->where('kind', BonusCalculator::MONTHLY)->pluck('user_id');
        $this->assertSame($monthly->count(), $monthly->unique()->count());
    }

    /**
     * @return array<int, ?int> user id => referrer id
     */
    private function randomMembers(int $n): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $hash = '$2y$04$abcdefghijklmnopqrstuuVd3c1q8v8ZQ5o4uZQ9C8s0r4lB2yW9e';
        $tree = [];
        foreach (array_chunk(range(1, $n), 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $i) {
                // A few big leaders, many small teams, some with no referrer.
                $ref = $i > 1 && mt_rand(1, 100) > 15 ? (mt_rand(1, 100) <= 40 ? mt_rand(1, min(20, $i - 1)) : mt_rand(1, $i - 1)) : null;
                $tree[$i] = $ref;
                $rows[] = ['id' => $i, 'first_name' => 'M'.$i, 'last_name' => 'Sim', 'phone' => '+25991'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                    'email' => "m$i@sim.example", 'password' => $hash, 'referrer_id' => $ref, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('users')->insert($rows);
        }

        $purchases = [];
        $p = function (int $u, string $start, string $end, ?string $paid = null, string $status = 'paid', int $amount = 500_000) use (&$purchases, $now): void {
            $purchases[] = ['user_id' => $u, 'amount' => $amount, 'wallet_used' => mt_rand(1, 10) === 1 ? intdiv($amount, 2) : 0, 'status' => $status,
                'period_start' => $start, 'period_end' => $end, 'paid_at' => $paid ?? $start, 'created_at' => $now, 'updated_at' => $now];
        };
        foreach (array_keys($tree) as $u) {
            $day = str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT);
            $kind = mt_rand(1, 100);
            if ($kind <= 65) {        // paid every day
                $p($u, "2026-09-$day 10:00:00", "2026-10-$day 10:00:00");
                $p($u, "2026-10-$day 10:00:00", "2026-11-$day 10:00:00", "2026-10-$day 09:00:00");
            } elseif ($kind <= 75) {  // grace: paid two days late
                $p($u, "2026-09-$day 10:00:00", "2026-10-$day 10:00:00");
                $p($u, "2026-10-$day 10:00:00", "2026-11-$day 10:00:00", date('Y-m-d H:i:s', strtotime("2026-10-$day 10:00:00 +2 days")));
            } elseif ($kind <= 85) {  // joined in October
                $p($u, "2026-10-$day 10:00:00", "2026-11-$day 10:00:00");
            } elseif ($kind <= 90) {  // October refunded
                $p($u, "2026-09-$day 10:00:00", "2026-10-$day 10:00:00");
                $p($u, "2026-10-$day 10:00:00", "2026-11-$day 10:00:00", "2026-10-$day 09:00:00", 'refunded');
            } elseif ($kind <= 95) {  // free month given by staff (no money)
                $p($u, "2026-09-$day 10:00:00", "2026-10-$day 10:00:00");
                $p($u, "2026-10-$day 10:00:00", "2026-11-$day 10:00:00", "2026-10-$day 09:00:00", 'paid', 0);
            } else {                  // left in October
                $p($u, "2026-09-$day 10:00:00", "2026-10-$day 10:00:00");
            }
        }
        foreach (array_chunk($purchases, 500) as $chunk) {
            DB::table('membership_purchases')->insert($chunk);
        }
        // Some members unlocked "other" badges in October (one-time bonuses, if any).
        $badges = Badge::query()->whereIn('key', ['first_sale', 'loyal_3', 'team_25'])->pluck('id')->all();
        $unlocks = [];
        foreach (array_rand($tree, 300) as $u) {
            $unlocks[] = ['user_id' => $u, 'badge_id' => $badges[array_rand($badges)], 'unlocked_at' => '2026-10-'.str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT).' 12:00:00'];
        }
        DB::table('user_badges')->insertOrIgnore($unlocks);

        return $tree;
    }

    /**
     * New payouts paid from October's membership money, at their worst:
     * first-payment bonus for every first real payment with an inviter, and
     * the welcome-back reward for every member who came back (none here: all
     * paid in September or are new).
     *
     * @param  array<int, ?int>  $tree
     */
    private function worstCaseNewPayouts(array $tree): int
    {
        $bonus = Settings::int('bonus.first_payment');
        $price = Settings::int('membership.price');
        $total = 0;
        $rows = DB::table('membership_purchases')->where('status', 'paid')->where('amount', '>', 0)
            ->where('paid_at', '>=', '2026-10-01 00:00:00')->where('paid_at', '<', '2026-11-01 00:00:00')->get(['user_id', 'amount', 'wallet_used', 'paid_at']);
        foreach ($rows as $r) {
            $first = ! DB::table('membership_purchases')->where('user_id', $r->user_id)->where('paid_at', '<', $r->paid_at)->where('amount', '>', 0)->exists();
            if ($first && ($tree[(int) $r->user_id] ?? null) !== null) {
                $real = (int) $r->amount - (int) $r->wallet_used;
                $total += min($bonus, intdiv($bonus * $real, $price));
            }
        }

        return $total;
    }

    /** Every level paid on every October membership, as if all referrers were active (worst case). @param array<int, ?int> $tree */
    private function worstCaseCommissions(array $tree): int
    {
        $rates = [Settings::int('referral.rate.membership.l1'), Settings::int('referral.rate.membership.l2'), Settings::int('referral.rate.membership.l3')];
        $total = 0;
        $rows = DB::table('membership_purchases')->where('status', 'paid')
            ->where('paid_at', '>=', '2026-10-01 00:00:00')->where('paid_at', '<', '2026-11-01 00:00:00')->get(['user_id', 'amount']);
        foreach ($rows as $r) {
            $up = $tree[(int) $r->user_id] ?? null;
            foreach ($rates as $rate) {
                if ($up === null) {
                    break;
                }
                $total += intdiv((int) $r->amount * $rate, 10_000) + 1; // rounded up
                $up = $tree[$up] ?? null;
            }
        }

        return $total;
    }
}
