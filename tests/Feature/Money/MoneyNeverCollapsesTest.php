<?php

namespace Tests\Feature\Money;

use App\Membership\MembershipService;
use App\Models\Commission;
use App\Models\Membership;
use App\Models\MembershipPurchase;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentService;
use App\Referral\ReferralCodes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Referral\ReferralTestCase;
use Tests\Support\MoneyModel;

/**
 * "The shop never collapses": the money rules over 24 months and 10,000
 * members, in normal times and in the classic ways such systems fall over
 * (very fast growth, then everybody stops). A short summary is printed in
 * the CI log.
 */
class MoneyNeverCollapsesTest extends ReferralTestCase
{
    /**
     * The fast model is only worth something if it pays what the real code
     * pays. Same people, same days, same refunds: through the real payments,
     * team earnings and first-payment bonus, member by member, to the santim.
     */
    public function test_the_fast_model_pays_exactly_what_the_real_code_pays(): void
    {
        $model = new MoneyModel(7);
        $model->days = 75;
        $model->maxMembers = 90;
        $model->products = false;
        $model->monthlyExtras = false;
        $model->record = true;
        $model->organic = fn (int $d) => $d < 20 ? 1.0 : 0.2;
        $model->inviteRate = fn (int $d) => 0.04;
        $model->renewRate = fn (int $d) => 0.75;
        $model->refundRate = fn (int $d) => 0.08;
        $model->run();
        $this->assertGreaterThan(60, $model->members);

        $base = now()->startOfDay()->addHours(10);
        $users = [];
        $payments = [];
        $payService = app(PaymentService::class);
        foreach ($model->events as $e) {
            $this->travelTo($base->copy()->addDays($e[1]));
            if ($e[0] === 'join') {
                $u = new User;
                $u->forceFill(['first_name' => 'M'.$e[2], 'last_name' => 'Sim', 'phone' => '+2519009'.str_pad((string) $e[2], 5, '0', STR_PAD_LEFT),
                    'password' => 'x', 'role' => 'customer', 'referrer_id' => $e[3] >= 0 ? $users[$e[3]]->id : null, 'referred_at' => now()])->save();
                app(ReferralCodes::class)->assign($u);
                $users[$e[2]] = $u;
            } elseif ($e[0] === 'pay') {
                $purchase = app(MembershipService::class)->newPurchase($users[$e[2]]->fresh());
                $p = $payService->submitProof($purchase, Payment::BANK, UploadedFile::fake()->image('r.jpg', 20, 20), 'SIM', null);
                $payService->approveProof($p, $this->admin->id);
                $payments[$e[3]] = $p->fresh();
                // Same dates as the model (its months are 30 days).
                MembershipPurchase::query()->whereKey($purchase->id)->update(['period_start' => $base->copy()->addDays($e[4]), 'period_end' => $base->copy()->addDays($e[5])]);
                Membership::query()->where('user_id', $users[$e[2]]->id)->update(['status' => 'active',
                    'current_period_end' => $base->copy()->addDays($e[5]), 'grace_ends_at' => $base->copy()->addDays($e[5] + 3)]);
            } else {
                $payService->refund($payments[$e[2]]->fresh(), $payments[$e[2]]->amount, 'Simulation refund', $this->admin->id);
            }
        }
        $real = [];
        foreach ($users as $m => $u) {
            $real[$m] = (int) Commission::query()->where('recipient_id', $u->id)->where('status', '!=', Commission::SKIPPED)->sum(DB::raw('amount - reversed_amount'))
                + (int) DB::table('rewards')->where('user_id', $u->id)->sum(DB::raw('amount - reversed_amount'));
        }
        $this->assertSame($model->earnedBy, $real, 'the fast model and the real code pay every member the same');
        $this->assertGreaterThan(0, array_sum($real));
        $this->assertGreaterThan(0, collect($model->events)->where(0, 'refund')->count(), 'refunds were part of it');
    }

    /** @return array<string, array{0: callable(MoneyModel): void}> */
    public static function scenarios(): array
    {
        return [
            'steady growth to 10,000 members' => [function (MoneyModel $m) {
                $m->organic = fn (int $d) => 4.0;
                $m->inviteRate = fn (int $d) => 0.012;
            }],
            'very fast growth, then nobody new and most stop paying' => [function (MoneyModel $m) {
                $m->organic = fn (int $d) => $d < 150 ? 10.0 : 0.0;
                $m->inviteRate = fn (int $d) => $d < 150 ? 0.05 : 0.0;     // everyone brings ~1.5 people a month…
                $m->renewRate = fn (int $d) => $d < 150 ? 0.9 : ($d < 300 ? 0.4 : 0.0); // …then panic, then everyone leaves
            }],
            'growth, then a wave of refunds' => [function (MoneyModel $m) {
                $m->organic = fn (int $d) => 6.0;
                $m->inviteRate = fn (int $d) => 0.015;
                $m->refundRate = fn (int $d) => $d >= 300 && $d < 360 ? 0.4 : 0.02;
            }],
        ];
    }

    /** @param callable(MoneyModel): void $setUp */
    #[DataProvider('scenarios')]
    public function test_24_months_with_up_to_10000_members_the_shop_stays_in_profit_and_can_pay_everyone(callable $setUp): void
    {
        $model = new MoneyModel(2026);
        $setUp($model);
        $r = $model->run(); // throws on the first day the books don't add up or the shop can't pay what it owes members

        foreach ($model->months as $i => $month) {
            if ($month['money_in'] > 0) {
                $this->assertLessThanOrEqual(35, 100 * $month['earned_for_month'] / $month['money_in'], 'month '.($i + 1).': members got more than 35%');
                $this->assertGreaterThan(0, $month['money_in'] - $month['earned_for_month'] - $month['product_costs'], 'month '.($i + 1).': the shop made a loss');
            }
            $this->assertGreaterThanOrEqual($month['owed'], $month['cash'], 'month '.($i + 1).': the shop could not pay every cash-out');
        }
        $this->assertGreaterThanOrEqual(65.0, $r['worst_kept_percent']);
        $this->assertGreaterThan(0, $r['profit_after_product_costs']);
        $this->assertGreaterThanOrEqual($r['owed_at_end'], $r['cash_left']);

        $etb = fn (int $s) => number_format(intdiv($s, 100));
        fwrite(STDERR, sprintf("\n[simulation] %s: %s members · money in %s ETB · paid to members %s ETB · shop kept %s%% · worst month %d (kept %s%%) · cash-outs %s ETB · cash left %s ETB ≥ owed %s ETB\n",
            $this->dataName(), number_format($r['members']), $etb($r['money_in']), $etb($r['paid_to_members']), $r['kept_percent'],
            $r['worst_month'], $r['worst_kept_percent'], $etb($r['cash_out']), $etb($r['cash_left']), $etb($r['owed_at_end'])));
    }
}
