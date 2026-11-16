<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Membership\Gifts;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Buy a gift month: details + receipt in one go (no account needed). */
class GiftController extends Controller
{
    public function store(Request $request, Gifts $gifts, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'giver_name' => ['required', 'string', 'max:80'], 'giver_phone' => ['required', 'string', 'max:20'],
            'giver_email' => ['nullable', 'email', 'max:160'],
            'receiver_name' => ['required', 'string', 'max:80'], 'receiver_contact' => ['required', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:300'],
            'method' => ['required', Rule::in([Payment::BANK, Payment::TELEBIRR])],
            'reference' => ['nullable', 'string', 'max:120'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'website' => ['nullable', 'max:0'], // spam trap
        ]);
        $giver = auth('sanctum')->user();
        try {
            DB::transaction(function () use ($data, $giver, $request, $gifts, $payments) {
                $gift = $gifts->create($data, $giver instanceof User ? $giver : null, $request);
                $payments->submitProof($gift, $data['method'], $request->file('proof'), $data['reference'] ?? null, $gift->giver_phone);
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['giver_phone' => $e->getMessage()]);
        }

        return response()->json(['data' => ['message' => 'Thank you! We are checking your receipt. When it is OK, we send the gift link to '
            .strip_tags($data['receiver_name']).' and a message to you.']], 201);
    }
}
