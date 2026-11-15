<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Ledger\Posting;
use App\Ledger\Wallet;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Support\Settings;
use App\Wallet\CashOuts;
use App\Wallet\PayoutAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $history = Posting::query()->with('entry:id,type,description,created_at')
            ->whereHas('account', fn ($q) => $q->where('user_id', $user->id)->where('code', 'like', '%:available'))
            ->latest('id')->limit(100)->get()
            ->map(fn (Posting $p) => ['type' => $p->entry->type, 'description' => $p->entry->description,
                'amount' => -$p->amount, 'at' => $p->entry->created_at->toIso8601String()]);

        return response()->json(['data' => [
            'balances' => Wallet::for($user)->summary(),
            'cashout_min' => Settings::int('wallet.cashout_min'),
            'payout_account' => app(PayoutAccounts::class)->current($user)?->present(),
            'history' => $history,
            'cashouts' => PayoutRequest::query()->where('user_id', $user->id)->latest('id')->limit(50)->get()
                ->map(fn (PayoutRequest $r) => ['id' => $r->id, 'amount' => $r->amount, 'method' => $r->method, 'account' => $r->maskedAccount(),
                    'status' => $r->status === 'on_hold' ? 'requested' : $r->status, 'due_at' => $r->due_at->toIso8601String(),
                    'reference' => $r->payout_reference, 'reject_reason' => $r->reject_reason]),
        ]]);
    }

    /** Add or change the cash-out account (password + 2FA code when on; 48-hour wait). */
    public function savePayoutAccount(Request $request, PayoutAccounts $accounts): JsonResponse
    {
        $data = $request->validate(self::accountRules() + ['current_password' => ['required', 'string'], 'code' => ['nullable', 'string', 'max:32']]);
        $account = $accounts->save($request->user(), $data, $data['current_password'], $data['code'] ?? null);

        return response()->json(['data' => $account->present()]);
    }

    /** Send an Idempotency-Key header so a retry never creates a second request. */
    public function cashOut(Request $request, CashOuts $cashOuts): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:100000000']]);
        $key = $request->header('Idempotency-Key');
        $r = $cashOuts->request($request->user(), $data['amount'], is_string($key) && $key !== '' ? $key : null);

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status === 'on_hold' ? 'requested' : $r->status,
            'due_at' => $r->due_at->toIso8601String()]], 201);
    }

    /** @return array<string, list<mixed>> */
    public static function accountRules(): array
    {
        return [
            'method' => ['required', Rule::in(['telebirr', 'bank'])],
            'account_name' => ['required', 'string', 'max:160'],
            'account_number' => ['required', 'string', 'max:40', 'regex:/^[0-9+\s-]{6,40}$/'],
            'bank_name' => ['nullable', 'required_if:method,bank', 'string', 'max:120'],
        ];
    }
}
