<?php

namespace Tests\Support;

use App\Models\Badge;
use App\Support\Settings;
use RuntimeException;

/**
 * A fast copy of the shop's money rules, to run 10,000 members over 24 months
 * in seconds (the real code would need hours). Day by day: people join
 * (through an invite or on their own), pay 5,000 ETB memberships, renew or
 * stop, buy products, ask for refunds and cash-outs; team earnings (3
 * levels, only active members earn), first-payment bonus, loyalty reward,
 * monthly badge bonus (Bronze…Diamond, team limit, budget) and contest and
 * challenge prizes (at their full budget, the worst case) are paid.
 *
 * All numbers come from the real settings and badges. The membership part
 * is checked against the real code by MoneyNeverCollapsesTest::
 * test_the_fast_model_pays_exactly_what_the_real_code_pays.
 *
 * Money is in santim (1/100 ETB). Months are 30 days.
 */
final class MoneyModel
{
    // Scenario knobs.
    public int $days = 720;

    public int $maxMembers = 10_000;

    /** New members per day who come without an invite (on day d). @var callable(int):float */
    public $organic;

    /** Invites per active member per day (on day d). @var callable(int):float */
    public $inviteRate;

    /** Chance a member renews when their month ends (on day d). @var callable(int):float */
    public $renewRate;

    /** Chance a payment is refunded (on day d). @var callable(int):float */
    public $refundRate;

    public float $orderRate = 0.01;     // product orders per active member per day

    public float $cashOutRate = 0.15;   // chance per day that a member with ≥ the minimum cashes it all out

    public bool $products = true;

    public bool $monthlyExtras = true;  // badge bonus, loyalty, contests, challenges

    /** Calibration: [day, member] joins, [day, member] payments and [day, payment] refunds are recorded. */
    public bool $record = false;

    /** @var list<array<int, int|string>> [kind, day, …] */
    public array $events = [];

    // Rules (from the real settings).
    private int $price;

    /** @var array<int,int> level => bps */
    private array $memberRates;

    /** @var array<int,int> */
    private array $productRates;

    private int $firstBonus;

    private int $loyalty;

    private int $loyaltyEvery;

    private int $pending;

    private int $grace;

    private int $discountBps;

    private int $cashOutMin;

    private int $bonusBudget;

    private int $teamLimit;

    private int $contestBudget;

    private int $challengeBudget;

    /** @var list<array{0:int,1:int}> [threshold, monthly bonus], highest first */
    private array $ladder;

    // People.
    /** @var array<int,int> */
    private array $ref = [];

    /** @var array<int,int> paid until this day (exclusive); -1 = never paid */
    private array $end = [];

    /** @var array<int,int> paid without a gap since this day */
    private array $since = [];

    /** @var array<int,bool> */
    private array $stopped = [];

    /** @var array<int,int> */
    private array $l1Count = [];

    /** @var array<int,list<int>> */
    private array $children = [];

    /** @var array<int,array<int,true>> members whose month ends on that day */
    private array $due = [];

    /** @var array<int,int> */
    private array $available = [];

    /** @var array<int,int> */
    private array $loyaltyPaid = [];

    /** @var array<int,array<int,int>> day => member => santim that becomes ready that day */
    private array $ready = [];

    /** @var array<int,array{0:int,1:int,2:list<array{0:int,1:int,2:int}>,3:int}> payment id => [member, amount, [[who, amount, readyDay]], day paid] */
    private array $payments = [];

    /** @var array<int,array<int,int>> day => payment ids refunded that day */
    private array $refundOn = [];

    // Books (double entry: every post adds up to 0).
    /** @var array<string,int> */
    public array $books = ['cash' => 0, 'sales' => 0, 'members_pending' => 0, 'members_ready' => 0, 'members_asked' => 0,
        'payouts_to_members' => 0, 'product_costs' => 0, 'refunds' => 0];

    /** @var array<int,array<string,int>> month => totals */
    public array $months = [];

    /** @var array<int,int> member => santim earned (after money taken back), for the calibration */
    public array $earnedBy = [];

    public int $members = 0;

    private int $seed;

    public function __construct(int $seed = 1)
    {
        $this->seed = $seed;
        mt_srand($seed);
        $this->organic = fn (int $d) => 2.0;
        $this->inviteRate = fn (int $d) => 0.01;
        $this->renewRate = fn (int $d) => 0.85;
        $this->refundRate = fn (int $d) => 0.02;
        $this->price = Settings::int('membership.price');
        $this->memberRates = [1 => Settings::int('referral.rate.membership.l1'), 2 => Settings::int('referral.rate.membership.l2'), 3 => Settings::int('referral.rate.membership.l3')];
        $this->productRates = [1 => Settings::int('referral.rate.products.l1'), 2 => Settings::int('referral.rate.products.l2'), 3 => Settings::int('referral.rate.products.l3')];
        $this->firstBonus = Settings::int('bonus.first_payment');
        $this->loyalty = Settings::int('loyalty.reward');
        $this->loyaltyEvery = max(1, Settings::int('loyalty.every_months'));
        $this->pending = Settings::int('referral.pending_days');
        $this->grace = Settings::int('membership.grace_days');
        $this->discountBps = Settings::int('pricing.member_discount_bps');
        $this->cashOutMin = Settings::int('wallet.cashout_min');
        $this->bonusBudget = Settings::int('bonus.budget');
        $this->teamLimit = Settings::int('bonus.team_limit');
        $this->contestBudget = Settings::int('contest.budget');
        $this->challengeBudget = Settings::int('challenge.budget');
        $this->ladder = Badge::query()->where('is_active', true)->where('rule', 'active_level1')->where('monthly_bonus', '>', 0)
            ->orderByDesc('threshold')->get(['threshold', 'monthly_bonus'])->map(fn ($b) => [(int) $b->threshold, (int) $b->monthly_bonus])->all();
    }

    private function chance(float $p): bool
    {
        return $p > 0 && mt_rand() / mt_getrandmax() < $p;
    }

    /** Whole people from a rate like 2.4: 2, and a 40% chance of a 3rd. */
    private function count(float $rate): int
    {
        $n = (int) floor($rate);

        return $n + ($this->chance($rate - $n) ? 1 : 0);
    }

    private function active(int $m, int $day): bool
    {
        return $this->end[$m] >= 0 && $day <= $this->end[$m] + $this->grace; // like the shop: until the last grace day
    }

    private function post(array $lines): void
    {
        if (array_sum($lines) !== 0) {
            throw new RuntimeException('Unbalanced entry: '.json_encode($lines));
        }
        foreach ($lines as $acc => $amount) {
            $this->books[$acc] += $amount;
        }
    }

    private function month(int $day): int
    {
        return intdiv($day, 30);
    }

    private function add(int $day, string $key, int $amount): void
    {
        $m = $this->month($day);
        $this->months[$m] ??= ['money_in' => 0, 'membership_in' => 0, 'refunds' => 0, 'earned_for_month' => 0, 'members' => 0, 'active' => 0,
            'cash_out' => 0, 'owed' => 0, 'cash' => 0, 'new' => 0, 'product_costs' => 0];
        $this->months[$m][$key] += $amount;
    }

    public function join(int $day, int $referrer): int
    {
        $m = $this->members++;
        $this->ref[$m] = $referrer;
        $this->end[$m] = -1;
        $this->since[$m] = -1;
        $this->stopped[$m] = false;
        $this->l1Count[$m] = 0;
        $this->available[$m] = 0;
        $this->loyaltyPaid[$m] = 0;
        $this->earnedBy[$m] = 0;
        if ($referrer >= 0) {
            $this->children[$referrer][] = $m;
        }
        $this->add($day, 'new', 1);
        if ($this->record) {
            $this->events[] = ['join', $day, $m, $referrer];
        }

        return $m;
    }

    /** Someone earns: on the way until $day + 14. */
    private function earn(int $who, int $amount, int $day, int $moneyDay): array
    {
        $ready = $day + $this->pending;
        $this->ready[$ready][$who] = ($this->ready[$ready][$who] ?? 0) + $amount;
        $this->post(['payouts_to_members' => $amount, 'members_pending' => -$amount]);
        $this->earnedBy[$who] += $amount;
        $this->add($moneyDay, 'earned_for_month', $amount);

        return [$who, $amount, $ready];
    }

    public function payMembership(int $m, int $day): int
    {
        $first = $this->end[$m] < 0;
        $continuing = $this->active($m, $day);
        $start = $continuing ? max($day, $this->end[$m]) : $day;
        if (! $continuing) {
            $this->since[$m] = $day;
            $this->loyaltyPaid[$m] = 0;
        }
        if ($this->end[$m] >= 0) {
            unset($this->due[$this->end[$m]][$m]);
        }
        $this->end[$m] = $start + 30;
        $this->due[$this->end[$m]][$m] = true;
        $this->post(['cash' => $this->price, 'sales' => -$this->price]);
        $this->add($day, 'money_in', $this->price);
        $this->add($day, 'membership_in', $this->price);
        $id = count($this->payments);
        $credits = [];
        // Team earnings: 3 levels up, only to active members (a skipped level is kept by the shop, not passed up).
        for ($level = 1, $up = $this->ref[$m]; $level <= 3 && $up >= 0; $level++, $up = $this->ref[$up]) {
            if ($this->active($up, $day)) {
                $credits[] = $this->earn($up, intdiv($this->price * $this->memberRates[$level], 10_000), $day, $day);
            }
        }
        if ($first && $this->ref[$m] >= 0 && $this->active($this->ref[$m], $day) && $this->firstBonus > 0) {
            $credits[] = $this->earn($this->ref[$m], $this->firstBonus, $day, $day);
        }
        if ($first && $this->ref[$m] >= 0) {
            $this->l1Count[$this->ref[$m]]++;
        }
        $this->payments[$id] = [$m, $this->price, $credits, $day];
        if ($this->record) {
            $this->events[] = ['pay', $day, $m, $id, $start, $this->end[$m]];
        }
        if ($this->chance(($this->refundRate)($day))) {
            $this->refundOn[$day + mt_rand(1, $this->pending - 1)][] = $id;
        }

        return $id;
    }

    /** A full refund within the 14 days: the money goes back and every earning from it is taken back. */
    public function refund(int $id, int $day): void
    {
        [$m, $amount, $credits, $paidDay] = $this->payments[$id];
        $this->post(['cash' => -$amount, 'refunds' => $amount]);
        $this->add($day, 'refunds', $amount);
        // Counted against the month the money came in (that month's "kept" share).
        $this->add($paidDay, 'money_in', -$amount);
        $this->add($paidDay, 'membership_in', -$amount);
        $this->monthPayers[$this->month($paidDay)][$m] -= $amount;
        foreach ($credits as [$who, $credit, $ready]) {
            if ($ready > $day) {
                $this->ready[$ready][$who] -= $credit;
                $this->post(['members_pending' => $credit, 'payouts_to_members' => -$credit]);
            } else {
                $this->available[$who] -= $credit; // can go below 0: the next earnings fill it first
                $this->post(['members_ready' => $credit, 'payouts_to_members' => -$credit]);
            }
            $this->earnedBy[$who] -= $credit;
            $this->add($paidDay, 'earned_for_month', -$credit);
        }
        if ($this->end[$m] >= 0) {
            unset($this->due[$this->end[$m]][$m]);
        }
        $this->end[$m] = $day - $this->grace - 1; // access ends now
        $this->stopped[$m] = true;
        if ($this->record) {
            $this->events[] = ['refund', $day, $id, $m];
        }
    }

    private function productOrder(int $day, int $buyer, bool $member, int $referrer): void
    {
        $list = mt_rand(100_000, 500_000);
        $paid = $member ? $list - intdiv($list * $this->discountBps, 10_000) : $list;
        $cost = intdiv($list * 40, 100); // the shop's 2.5× price rule
        $this->post(['cash' => $paid, 'sales' => -$paid]);
        $this->post(['product_costs' => $cost, 'cash' => -$cost]);
        $this->add($day, 'money_in', $paid);
        $this->add($day, 'product_costs', $cost);
        $start = $member ? $this->ref[$buyer] : $referrer;
        for ($level = 1, $up = $start; $level <= 3 && $up >= 0; $level++, $up = $this->ref[$up]) {
            if ($this->active($up, $day)) {
                $this->earn($up, intdiv($paid * $this->productRates[$level], 10_000), $day, $day);
            }
        }
    }

    /** Badge bonus, contests and challenges for the month that just ended (day = first day of the new month). */
    private function monthEnd(int $day): void
    {
        $m = $this->month($day) - 1;
        $from = $m * 30;
        $to = $from + 30;
        $money = $this->months[$m]['membership_in'] ?? 0;
        $wanted = [];
        $teamMoney = [];
        foreach ($this->monthPayers[$m] ?? [] as $who => $amount) {
            $up = $this->ref[$who];
            if ($up >= 0) {
                $teamMoney[$up] = ($teamMoney[$up] ?? 0) + $amount;
            }
        }
        foreach ($teamMoney as $who => $tm) {
            if (! ($this->since[$who] >= 0 && $this->since[$who] <= $from && $this->end[$who] >= $to)) {
                continue; // not paid for every day of the month
            }
            $active = 0;
            foreach ($this->children[$who] ?? [] as $c) {
                $active += $this->end[$c] >= $to ? 1 : 0; // paid at the last second (no grace)
            }
            foreach ($this->ladder as [$threshold, $bonus]) {
                if ($active >= $threshold) {
                    $wanted[$who] = min($bonus, intdiv($tm * $this->teamLimit, 10_000));
                    break;
                }
            }
        }
        $budget = intdiv($money * $this->bonusBudget, 10_000);
        $sum = array_sum($wanted);
        foreach ($wanted as $who => $w) {
            $pay = $sum > $budget ? intdiv($w * $budget, max(1, $sum)) : $w;
            if ($pay > 0) {
                $this->earnFor($who, $pay, $day, $from);
            }
        }
        // Contest and challenge prizes: the full budget, to the members who brought the most people (worst case).
        $prizes = intdiv($money * $this->contestBudget, 10_000) + intdiv($money * $this->challengeBudget, 10_000);
        if ($prizes > 0 && $teamMoney !== []) {
            arsort($teamMoney);
            $top = array_slice(array_keys($teamMoney), 0, 10);
            foreach ($top as $i => $who) {
                $this->earnFor($who, intdiv($prizes, count($top)) + ($i === 0 ? $prizes % count($top) : 0), $day, $from);
            }
        }
    }

    /** @var array<int,array<int,int>> month => member => membership money paid */
    private array $monthPayers = [];

    private function earnFor(int $who, int $amount, int $day, int $moneyDay): void
    {
        $this->earn($who, $amount, $day, $moneyDay);
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $worstKept = 100.0;
        $worstMonth = 0;
        for ($day = 0; $day < $this->days; $day++) {
            if ($day > 0 && $day % 30 === 0 && $this->monthlyExtras) {
                $this->monthEnd($day);
            }
            // Refunds due today.
            foreach ($this->refundOn[$day] ?? [] as $id) {
                $this->refund($id, $day);
            }
            // Earnings that become ready.
            foreach ($this->ready[$day] ?? [] as $who => $amount) {
                $this->available[$who] += $amount;
                $this->post(['members_pending' => $amount, 'members_ready' => -$amount]);
            }
            unset($this->ready[$day]);
            // Renewals.
            foreach (array_keys($this->due[$day] ?? []) as $m) {
                if (! $this->stopped[$m] && $this->chance(($this->renewRate)($day))) {
                    $this->pay($m, $day);
                } else {
                    $this->stopped[$m] = true;
                }
            }
            // New people: on their own and through invites (each pays their first month the same day).
            $active = 0;
            $actives = [];
            for ($m = 0; $m < $this->members; $m++) {
                if ($this->active($m, $day)) {
                    $active++;
                    $actives[] = $m;
                }
            }
            $new = [];
            for ($i = $this->count(($this->organic)($day)); $i > 0 && $this->members < $this->maxMembers; $i--) {
                $new[] = $this->join($day, -1);
            }
            $invites = $this->count($active * ($this->inviteRate)($day));
            for ($i = 0; $i < $invites && $this->members < $this->maxMembers && $actives !== []; $i++) {
                $new[] = $this->join($day, $actives[mt_rand(0, count($actives) - 1)]);
            }
            foreach ($new as $m) {
                $this->pay($m, $day);
            }
            // Loyalty reward after every 3 months in a row.
            if ($this->monthlyExtras && $this->loyalty > 0) {
                foreach ($actives as $m) {
                    $months = intdiv($day - $this->since[$m], 30);
                    if ($this->since[$m] >= 0 && $this->end[$m] > $day && intdiv($months, $this->loyaltyEvery) > $this->loyaltyPaid[$m]) {
                        $this->loyaltyPaid[$m]++;
                        $this->available[$m] += $this->loyalty;
                        $this->post(['payouts_to_members' => $this->loyalty, 'members_ready' => -$this->loyalty]);
                        $this->earnedBy[$m] += $this->loyalty;
                        $this->add($day, 'earned_for_month', $this->loyalty);
                    }
                }
            }
            // Product orders: members, and guests who came through a member's link half of the time.
            if ($this->products && $actives !== []) {
                for ($i = $this->count(count($actives) * $this->orderRate); $i > 0; $i--) {
                    $buyer = $actives[mt_rand(0, count($actives) - 1)];
                    $this->chance(0.5) ? $this->productOrder($day, $buyer, true, -1)
                        : $this->productOrder($day, -1, false, $this->chance(0.5) ? $actives[mt_rand(0, count($actives) - 1)] : -1);
                }
            }
            // Cash-outs: the whole ready amount, when it is at least the minimum (paid at once here: worst case for the shop's cash).
            foreach ($actives as $m) {
                if ($this->available[$m] >= $this->cashOutMin && $this->chance($this->cashOutRate)) {
                    $amount = $this->available[$m];
                    $this->available[$m] = 0;
                    $this->post(['members_ready' => $amount, 'cash' => -$amount]);
                    $this->add($day, 'cash_out', $amount);
                }
            }
            // Every day: the books add up, and the shop has the money for everything it owes members.
            if (array_sum($this->books) !== 0) {
                throw new RuntimeException("Day $day: the books don't add up.");
            }
            $owed = -($this->books['members_pending'] + $this->books['members_ready'] + $this->books['members_asked']);
            if ($this->books['cash'] < $owed) {
                throw new RuntimeException("Day $day: the shop can't pay what it owes members (cash {$this->books['cash']} < owed $owed).");
            }
            $mm = $this->month($day);
            $this->add($day, 'members', 0);
            $this->months[$mm]['members'] = $this->members;
            $this->months[$mm]['active'] = $active;
            $this->months[$mm]['owed'] = $owed;
            $this->months[$mm]['cash'] = $this->books['cash'];
        }
        // Every month: what members got for that month's money, and what the shop kept.
        $in = 0;
        $out = 0;
        foreach ($this->months as $m => $row) {
            $in += $row['money_in'];
            $out += $row['earned_for_month'];
            if ($row['money_in'] > 0) {
                $kept = 100 * (1 - $row['earned_for_month'] / $row['money_in']);
                if ($kept < $worstKept) {
                    [$worstKept, $worstMonth] = [$kept, $m + 1];
                }
            }
        }

        return ['members' => $this->members, 'money_in' => $in, 'paid_to_members' => $out, 'kept_percent' => round(100 * (1 - $out / max(1, $in)), 1),
            'worst_month' => $worstMonth, 'worst_kept_percent' => round($worstKept, 1), 'cash_out' => array_sum(array_column($this->months, 'cash_out')),
            'product_costs' => $this->books['product_costs'], 'profit_after_product_costs' => $in - $out - $this->books['product_costs'],
            'cash_left' => $this->books['cash'], 'owed_at_end' => end($this->months)['owed'] ?? 0];
    }

    private function pay(int $m, int $day): void
    {
        $id = $this->payMembership($m, $day);
        $mm = $this->month($day);
        $this->monthPayers[$mm][$m] = ($this->monthPayers[$mm][$m] ?? 0) + $this->payments[$id][1];
    }
}
