<?php

namespace App\Http\Controllers\Admin;

use App\Badges\BonusCalculator;
use App\Badges\BonusRun;
use App\Badges\ProfitGuard;
use App\Http\Controllers\Controller;
use App\Models\BadgeBonus;
use App\Models\BadgeBonusRun;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BadgeBonusController extends Controller
{
    public function __construct(private readonly BonusRun $runs, private readonly BonusCalculator $calculator) {}

    /** Everything for the Badge bonus page. Real money only (demo members are paid from demo money). */
    public function show(): JsonResponse
    {
        $last = BonusRun::lastMonth();
        $lastRun = $this->runs->runFor($last);
        $preview = $lastRun ? null : $this->calculator->plan($last)['pools'][0] ?? null;
        $now = now();
        $forecast = $this->calculator->plan($now->copy(), null, $now)['pools'][0] ?? null;
        $lastRevenue = $lastRun ? $lastRun->revenue : ($preview['revenue'] ?? 0);

        return response()->json(['data' => [
            'guard' => ProfitGuard::worstCase() + ['keep_line' => ProfitGuard::keepLine()],
            'settings' => ['budget' => Settings::int('bonus.budget'), 'team_limit' => Settings::int('bonus.team_limit'),
                'pending_days' => Settings::int('bonus.pending_days')],
            'ladder' => BonusCalculator::ladder()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'color' => $b->color,
                'threshold' => $b->threshold, 'monthly_bonus' => $b->monthly_bonus])->values(),
            'first_payout_done' => $this->runs->firstPayoutDone(),
            'last_month' => [
                'month' => $last->format('Y-m'), 'label' => $last->format('F Y'),
                'run' => $lastRun ? $this->runRow($lastRun) : null,
                'preview' => $preview ? $this->pool($preview) : null,
            ],
            'forecast' => [
                'month' => $now->format('Y-m'), 'label' => $now->format('F Y'),
                'members' => count($forecast['lines'] ?? []),
                'wanted' => array_sum(array_column($forecast['lines'] ?? [], 'wanted')),
                'money_so_far' => $forecast['revenue'] ?? 0,
                'budget_so_far' => $forecast['budget'] ?? 0,
                'budget_estimate' => intdiv($lastRevenue * Settings::int('bonus.budget'), 10_000),
                'skipped' => $this->runs->runFor($now)?->status === BadgeBonusRun::SKIPPED,
            ],
            'history' => BadgeBonusRun::query()->where('is_demo', false)->latest('month')->limit(24)->get()
                ->map(fn (BadgeBonusRun $r) => $this->runRow($r))->values(),
        ]]);
    }

    /** The members paid for one month (or the dry run when it is not paid yet). */
    public function month(string $month): JsonResponse
    {
        $m = BonusRun::monthOf($month);
        $run = $this->runs->runFor($m);
        if ($run) {
            return response()->json(['data' => ['run' => $this->runRow($run), 'lines' => BadgeBonus::query()
                ->with(['user:id,first_name,last_name,phone', 'badge:id,name'])->where('run_id', $run->id)->orderByDesc('amount')->limit(2000)->get()
                ->map(fn (BadgeBonus $b) => ['user_id' => $b->user_id, 'name' => $b->user ? trim($b->user->first_name.' '.$b->user->last_name) : '',
                    'badge' => $b->badge->name ?? '', 'kind' => $b->kind, 'wanted' => $b->wanted, 'amount' => $b->outstanding(),
                    'limited_by' => $b->limited_by, 'team_active' => $b->team_active, 'team_money' => $b->team_money,
                    'status' => $b->on_hold ? 'on_hold' : $b->status])->values()]]);
        }
        $pool = $this->calculator->plan($m)['pools'][0] ?? null;

        return response()->json(['data' => ['run' => null, 'preview' => $pool ? $this->pool($pool) : null]]);
    }

    public function pay(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/']]);
        try {
            $run = $this->runs->pay(BonusRun::monthOf($data['month']), $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['month' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->runRow($run)]);
    }

    public function skip(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'], 'note' => ['nullable', 'string', 'max:500']]);
        try {
            $run = $this->runs->skip(BonusRun::monthOf($data['month']), $request->user()?->id, $data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['month' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->runRow($run)]);
    }

    /** @return array<string, mixed> */
    private function runRow(BadgeBonusRun $r): array
    {
        return ['month' => $r->month->format('Y-m'), 'label' => $r->month->format('F Y'), 'status' => $r->status, 'revenue' => $r->revenue,
            'budget' => $r->budget, 'wanted' => $r->wanted, 'paid' => $r->paid, 'members' => $r->members, 'adjusted' => $r->adjusted,
            'note' => $r->note, 'at' => $r->created_at?->toIso8601String()];
    }

    /**
     * @param  array{revenue:int, budget:int, wanted:int, paid:int, adjusted:bool, lines: list<array<string, mixed>>}  $pool
     * @return array<string, mixed>
     */
    private function pool(array $pool): array
    {
        $lines = $pool['lines'];
        usort($lines, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return ['revenue' => $pool['revenue'], 'budget' => $pool['budget'], 'wanted' => $pool['wanted'], 'paid' => $pool['paid'],
            'adjusted' => $pool['adjusted'], 'members' => count(array_unique(array_column($lines, 'user_id'))),
            'lines' => array_slice($lines, 0, 2000)];
    }
}
