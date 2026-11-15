<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\PaymentService;
use App\Shop\GiftCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Buy a gift card online: amount, who it is for, and the receipt. */
class GiftCardController extends Controller
{
    public function store(Request $request, GiftCards $cards, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer'], 'buyer_name' => ['required', 'string', 'max:80'], 'buyer_phone' => ['required', 'string', 'max:20'],
            'buyer_email' => ['nullable', 'email', 'max:160'], 'recipient_name' => ['nullable', 'string', 'max:80'],
            'recipient_contact' => ['nullable', 'string', 'max:160'], 'message' => ['nullable', 'string', 'max:300'],
            'method' => ['required', Rule::in([Payment::BANK, Payment::TELEBIRR])], 'reference' => ['nullable', 'string', 'max:120'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'], 'website' => ['nullable', 'max:0'],
        ]);
        try {
            DB::transaction(function () use ($data, $cards, $payments, $request) {
                $card = $cards->create($data);
                $payments->submitProof($card, $data['method'], $request->file('proof'), $data['reference'] ?? null, $card->buyer_phone);
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return response()->json(['data' => ['message' => 'Thank you! When we have checked your receipt we send the gift card code by '
            .(isset($data['recipient_contact']) && $data['recipient_contact'] ? 'message to the receiver.' : 'message to you.')]], 201);
    }
}
