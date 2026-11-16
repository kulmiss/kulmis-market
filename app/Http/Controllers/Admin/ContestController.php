<?php

namespace App\Http\Controllers\Admin;

use App\Badges\ProfitGuard;
use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\Contest;
use App\Rewards\Contests;
use App\Support\Activity;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContestController extends Controller
{
    public function __construct(private readonly Contests $contests) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'contests' => Contest::query()->latest('starts_at')->limit(100)->get()->map(fn (Contest $c) => $this->present($c)),
            'challenges' => Challenge::query()->orderBy('target')->get()->map(fn (Challenge $c) => $c->only(['id', 'name', 'target', 'reward', 'monthly_cap', 'is_active'])
                + ['runs' => DB::table('challenge_runs')->where('challenge_id', $c->id)->latest('month')->limit(6)->get(['month', 'members', 'paid', 'was_cut'])]),
            'rules' => Contest::RULES,
            'budgets' => ['contest' => Settings::int('contest.budget'), 'challenge' => Settings::int('challenge.budget')],
            'guard' => ProfitGuard::worstCase() + ['keep_line' => ProfitGuard::keepLine()],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $c = DB::transaction(function () use ($request) {
            $c = new Contest($this->validated($request));
            $c->forceFill(['created_by' => $request->user()->id])->save();
            ProfitGuard::assertSafe();

            return $c;
        });
        Activity::log('contest.created', $c);

        return response()->json(['data' => $this->present($c)], 201);
    }

    public function update(Request $request, Contest $contest): JsonResponse
    {
        if ($contest->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'This contest is finished or cancelled.']);
        }
        if ($contest->starts_at->lte(now())) {
            // Fair play: once it started only the words can change.
            $contest->update($request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500']]));
        } else {
            DB::transaction(function () use ($request, $contest) {
                $contest->update($this->validated($request));
                ProfitGuard::assertSafe();
            });
        }
        Activity::log('contest.updated', $contest);

        return response()->json(['data' => $this->present($contest)]);
    }

    public function cancel(Contest $contest): JsonResponse
    {
        abort_if($contest->status !== 'active', 422, 'This contest is already finished.');
        $contest->update(['status' => 'cancelled']);
        Activity::log('contest.cancelled', $contest);

        return response()->json(['data' => $this->present($contest)]);
    }

    public function saveChallenge(Request $request, ?Challenge $challenge = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'target' => ['required', 'integer', 'min:1', 'max:1000'],
            'reward' => ['required', 'integer', 'min:100', 'max:100000000'], 'monthly_cap' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $challenge = DB::transaction(function () use ($data, $challenge) {
            $c = $challenge ?? new Challenge;
            $c->fill($data)->save();
            ProfitGuard::assertSafe();

            return $c;
        });
        Activity::log('challenge.saved', $challenge);

        return response()->json(['data' => $challenge], $challenge->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'],
            'rule' => ['required', Rule::in(array_keys(Contest::RULES))],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'prizes' => ['required', 'array', 'min:1', 'max:10'], 'prizes.*' => ['integer', 'min:0', 'max:100000000'],
            'min_score' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]) + ['min_score' => 1];
    }

    /** @return array<string, mixed> */
    private function present(Contest $c): array
    {
        $cap = $this->contests->cap($c);

        return $c->only(['id', 'name', 'description', 'rule', 'prizes', 'min_score', 'status', 'paid_total', 'was_cut', 'result']) + [
            'starts_at' => $c->starts_at->toIso8601String(), 'ends_at' => $c->ends_at->toIso8601String(),
            'settles_at' => $c->settlesAt()->toIso8601String(), 'phase' => $c->phase(), 'pool' => $c->pool(),
            'cap_now' => $cap, 'cap_line' => $c->pool() > $cap
                ? 'With the membership money paid so far in these dates, prizes would be cut to '.Money::format($cap).' in total ('.Settings::int('contest.budget') / 100 .'% budget).'
                : 'The full prizes fit in the budget so far.',
            'leaderboard' => in_array($c->phase(), ['running', 'checking'], true) ? $this->contests->leaderboard($c) : [],
        ];
    }
}
