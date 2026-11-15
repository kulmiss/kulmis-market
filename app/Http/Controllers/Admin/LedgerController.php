<?php

namespace App\Http\Controllers\Admin;

use App\Demo\Demo;
use App\Http\Controllers\Controller;
use App\Ledger\Account;
use App\Ledger\JournalEntry;
use App\Ledger\Ledger;
use App\Ledger\Wallet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LedgerController extends Controller
{
    public function __construct(private readonly Ledger $ledger) {}

    public function entries(Request $request): JsonResponse
    {
        $request->validate(['type' => ['nullable', 'string', 'max:60'], 'user_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'max:200']]);

        $page = JournalEntry::query()
            ->with(['postings.account:id,code,type,name,user_id'])
            ->when(! Demo::visible(), fn ($q) => $q->where('is_demo', false))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', 'like', $v.'%'))
            ->when($request->input('user_id'), fn ($q, $v) => $q->whereHas('postings.account', fn ($a) => $a->where('user_id', $v)))
            ->latest('id')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json($page);
    }

    public function accounts(): JsonResponse
    {
        // Real money only; demo postings are shown apart (never mixed into real totals).
        $sum = fn (Account $a, bool $demo) => (int) DB::table('ledger_postings')->where('ledger_account_id', $a->id)->where('is_demo', $demo)->sum('amount');
        $showDemo = Demo::visible();
        $rows = Account::query()->whereNull('user_id')->orderBy('code')->get()
            ->map(fn (Account $a) => ['code' => $a->code, 'type' => $a->type, 'name' => $a->name, 'balance' => $sum($a, false)]
                + ($showDemo ? ['demo_balance' => $sum($a, true)] : []));

        return response()->json(['data' => $rows]);
    }

    public function wallet(User $user): JsonResponse
    {
        return response()->json(['data' => Wallet::for($user)->summary()]);
    }

    /**
     * Manual correction of a member wallet. Always needs a reason and is
     * logged. Positive = credit the member, negative = debit.
     */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'bucket' => ['required', Rule::in([Wallet::AVAILABLE, Wallet::PENDING])],
            'amount' => ['required', 'integer', 'not_in:0', 'between:-100000000,100000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $user = User::query()->findOrFail($data['user_id']);
        $wallet = $this->ledger->userAccount($user, $data['bucket']);
        $amount = (int) $data['amount'];

        // Crediting a liability = negative posting on the wallet.
        $entry = $this->ledger->post('manual.adjustment', [
            [$wallet, -$amount],
            [Account::ADJUSTMENTS, $amount],
        ], reason: $data['reason'], actorId: $request->user()?->id, reference: $user, description: 'Manual wallet adjustment');

        return response()->json(['data' => ['entry' => $entry->uuid, 'wallet' => Wallet::for($user)->summary()]], 201);
    }
}
