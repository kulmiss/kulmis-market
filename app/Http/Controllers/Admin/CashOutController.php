<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Ledger\Wallet;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Support\TableQuery;
use App\Wallet\CashOuts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CashOutController extends Controller
{
    public function __construct(private readonly CashOuts $cashOuts) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['requested', 'on_hold', 'paid', 'rejected'])], 'overdue' => ['nullable', 'boolean']]);
        $page = PayoutRequest::query()->with('user:id,first_name,last_name,phone')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', ['requested', 'on_hold'])->where('due_at', '<', now()))
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('payout_reference', 'like', "%$v%")
                ->orWhereHas('user', fn ($u) => $u->where('first_name', 'like', "%$v%")->orWhere('last_name', 'like', "%$v%")->orWhere('phone', 'like', "%$v%"))))
            ->when(! $request->query('sort'), fn ($q) => $q->orderByRaw("CASE WHEN status IN ('requested','on_hold') THEN 0 ELSE 1 END")->orderBy('due_at'))
            ->when($request->query('sort'), fn ($q) => TableQuery::sort($q, $request, ['amount' => 'amount', 'status' => 'status', 'due' => 'due_at', 'requested' => 'created_at']))
            ->paginate(min(200, (int) $request->input('per_page', 50)));
        $page->getCollection()->each(fn (PayoutRequest $r) => $r->setAttribute('account', $r->maskedAccount()));

        return response()->json($page);
    }

    /** Full account number only here, for Super Admin to pay. */
    public function show(PayoutRequest $payout): JsonResponse
    {
        $account = $payout->payout_account_id ? PayoutAccount::query()->find($payout->payout_account_id) : null;

        return response()->json(['data' => $payout->load('user')->toArray() + [
            'account_number' => $payout->account_number,
            'account_verified' => $account?->verified_at !== null,
            'account_added_at' => $account?->created_at?->toIso8601String(),
            'wallet' => Wallet::for($payout->user)->summary(),
        ]]);
    }

    public function pay(Request $request, PayoutRequest $payout): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:120'], 'account_checked' => ['nullable', 'boolean']]);

        return $this->attempt(fn () => $this->cashOuts->markPaid($payout, $data['reference'], (int) $request->user()?->id, (bool) ($data['account_checked'] ?? false)));
    }

    public function reject(Request $request, PayoutRequest $payout): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->cashOuts->reject($payout, $data['reason'], (int) $request->user()?->id));
    }

    private function attempt(callable $fn): JsonResponse
    {
        try {
            return response()->json(['data' => $fn()]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['payout' => $e->getMessage()]);
        }
    }
}
