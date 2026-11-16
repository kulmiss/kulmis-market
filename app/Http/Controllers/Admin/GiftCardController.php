<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Ledger\Ledger;
use App\Models\GiftCard;
use App\Shop\GiftCards;
use App\Support\Activity;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class GiftCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = GiftCard::query()
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('last4', strtoupper((string) $v))->orWhere('buyer_name', 'like', "%$v%")->orWhere('buyer_phone', 'like', "%$v%")))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->tap(fn ($q) => TableQuery::sort($q, $request, ['amount' => 'amount', 'balance' => 'balance', 'created' => 'created_at', 'expires' => 'expires_at'], '-created_at'))
            ->paginate(min(200, (int) $request->input('per_page', 50)));

        return response()->json($page->toArray() + ['owed' => app(Ledger::class)->balance(GiftCard::ACCOUNT), 'amounts' => GiftCards::amounts()]);
    }

    /** Sold in the shop for cash: the code is shown only in this answer. */
    public function sell(Request $request, GiftCards $cards): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:10000', 'max:100000000'], 'buyer_name' => ['required', 'string', 'max:80'],
            'buyer_phone' => ['nullable', 'string', 'max:20']]);
        try {
            [$card, $code] = $cards->sellInShop($data['amount'], $data['buyer_name'], $data['buyer_phone'] ?? null, (int) $request->user()->id);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return response()->json(['data' => ['id' => $card->id, 'code' => implode('-', str_split($code, 4)), 'last4' => $card->last4]], 201);
    }

    public function disable(GiftCard $card): JsonResponse
    {
        $card->update(['status' => 'disabled']);
        Activity::log('giftcard.disabled', $card);

        return response()->json(['data' => ['status' => 'disabled']]);
    }
}
