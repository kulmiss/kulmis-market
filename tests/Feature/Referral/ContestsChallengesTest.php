<?php

namespace Tests\Feature\Referral;

use App\Badges\ProfitGuard;
use App\Fraud\FraudGuard;
use App\Models\Challenge;
use App\Models\Contest;
use App\Models\Reward;
use App\Payments\PaymentService;
use App\Rewards\Challenges;
use App\Rewards\Contests;
use App\Rewards\Rewards;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/** Referral contests (fixed prizes, paid after the check) and team challenges. */
class ContestsChallengesTest extends ReferralTestCase
{
    private function contest(array $extra = []): Contest
    {
        return Contest::query()->create(array_merge(['name' => 'Timket contest', 'rule' => 'new_paying_members',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'prizes' => [100_000, 50_000]], $extra));
    }

    public function test_leaderboard_then_prizes_after_the_check_only_for_real_payments(): void
    {
        Settings::set('contest.budget', 1_000); // 10%: the full prizes fit here
        $abebe = $this->member(null, 'Abebe');
        $chaltu = $this->member(null, 'Chaltu');
        $contest = $this->contest();
        foreach (['A1', 'A2', 'A3'] as $n) {
            $this->payMembership($this->member($abebe, $n));
        }
        $refunded = $this->payMembership($this->member($chaltu, 'C1'));
        $this->payMembership($this->member($chaltu, 'C2'));

        $board = app(Contests::class)->leaderboard($contest);
        $this->assertSame(['Abebe', 'Chaltu'], array_column($board, 'name'));
        $this->assertSame('3 new members', $board[0]['score_text']);
        $this->assertArrayNotHasKey('last_name', $board[0]);
        $this->actingAs($chaltu)->get('/account/contests')->assertOk()->assertSee('Timket contest')->assertSee('place 2');

        app(PaymentService::class)->refund($refunded, $refunded->amount, 'refund', $this->admin->id);

        // Not before the end + the 14-day check.
        $this->travel(11)->days();
        $this->assertSame(0, app(Contests::class)->settleDue());
        $this->travel(14)->days();
        $this->assertSame(1, app(Contests::class)->settleDue());
        $this->assertSame(0, app(Contests::class)->settleDue()); // never twice

        $contest->refresh();
        $this->assertSame('paid', $contest->status);
        $this->assertSame([3, 1], array_column($contest->result, 'score')); // the refunded one doesn't count
        $this->assertSame(100_000, Reward::query()->where('user_id', $abebe->id)->where('kind', 'contest')->value('amount'));
        $this->assertSame(50_000, Reward::query()->where('user_id', $chaltu->id)->where('kind', 'contest')->value('amount'));
        app(Rewards::class)->releaseDue();
        $this->assertSame(2, Reward::query()->where('kind', 'contest')->where('status', Reward::AVAILABLE)->count());
    }

    public function test_prizes_are_cut_to_the_budget_and_fraud_checks_come_first(): void
    {
        $abebe = $this->member(null, 'Abebe');
        $bekele = $this->member(null, 'Bekele');
        $contest = $this->contest(['prizes' => [1_000_000, 500_000]]); // much more than 2% of the money
        $this->payMembership($this->member($abebe, 'A1'));
        $this->payMembership($this->member($abebe, 'A2'));
        $this->payMembership($this->member($bekele, 'B1'));
        app(FraudGuard::class)->flag($abebe, 'manual');

        $this->travel(25)->days();
        app(Contests::class)->settleDue();
        $contest->refresh();
        $this->assertTrue($contest->was_cut);
        $cap = intdiv(3 * 500_000 * 200, 10_000);   // 2% of 15,000 ETB = 300 ETB
        $this->assertLessThanOrEqual($cap, $contest->paid_total);
        $this->assertSame(['Bekele'], array_column($contest->result, 'name')); // Abebe had an open fraud check
        $this->assertSame(0, Reward::query()->where('user_id', $abebe->id)->where('kind', 'contest')->count());
    }

    public function test_admin_creates_contests_inside_the_profit_guard(): void
    {
        $this->actingAsAdmin();
        $body = ['name' => 'Big one', 'rule' => 'new_paying_members', 'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDays(30)->toIso8601String(), 'prizes' => [300_000, 200_000, 100_000]];
        $id = $this->postJson('/api/v1/admin/contests', $body)->assertCreated()->assertJsonPath('data.pool', 600_000)->json('data.id');
        $this->assertSame(200, ProfitGuard::worstCase()['contests']);
        $this->getJson('/api/v1/admin/contests')->assertOk()->assertJsonPath('data.contests.0.phase', 'scheduled');

        $this->putJson('/api/v1/admin/settings', ['settings' => ['contest.budget' => 2_000]])->assertJsonValidationErrors('profit');

        // Once started, only the words can change.
        Contest::query()->whereKey($id)->update(['starts_at' => now()->subHour()]);
        $this->putJson("/api/v1/admin/contests/$id", $body + ['prizes' => [9_000_000]])->assertOk();
        $this->assertSame([300_000, 200_000, 100_000], Contest::query()->findOrFail($id)->prizes);
        $this->postJson("/api/v1/admin/contests/$id/cancel")->assertOk();
        $this->assertSame(0, ProfitGuard::worstCase()['contests']);

        $this->actingAsAdmin('support');
        $this->getJson('/api/v1/admin/contests')->assertForbidden();
    }

    public function test_team_challenge_paid_after_the_month_within_the_cap(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Africa/Addis_Ababa'));
        $c = Challenge::query()->create(['name' => 'Bring 2', 'target' => 2, 'reward' => 30_000, 'monthly_cap' => 40_000]);
        $a = $this->member(null, 'Almaz');
        $b = $this->member(null, 'Biruk');
        $lazy = $this->member(null, 'Lemma');
        foreach ([$a, $b, $lazy] as $m) {
            $m->membership()->update(['current_period_end' => '2026-12-31 00:00:00', 'grace_ends_at' => '2027-01-03 00:00:00']);
        }
        foreach ([$a, $a, $b, $b, $lazy] as $i => $who) {
            $this->payMembership($this->member($who, 'N'.$i));
        }
        $this->actingAs($a)->get('/account/contests')->assertSee('Bring 2')->assertSee('Done!');
        $this->assertSame(100, app(Challenges::class)->progress($a)[0]['percent']);
        $this->assertSame(50, app(Challenges::class)->progress($lazy)[0]['percent']);

        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00', 'Africa/Addis_Ababa'));
        $this->assertSame(0, app(Challenges::class)->settleDue()); // refund window not over yet
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00', 'Africa/Addis_Ababa'));
        $this->assertSame(1, app(Challenges::class)->settleDue());
        $this->assertSame(0, app(Challenges::class)->settleDue());

        $paid = Reward::query()->where('kind', 'challenge')->pluck('amount', 'user_id');
        $this->assertSame([$a->id, $b->id], $paid->keys()->sort()->values()->all());
        $this->assertSame([20_000, 20_000], $paid->values()->all()); // 2 × 300 = 600 > cap 400 → shared
        $this->assertSame(min(Settings::int('challenge.budget'), 300), ProfitGuard::worstCase()['challenges']); // 300 ÷ (2 × 5,000) = 3% → capped at 2%
    }
}
