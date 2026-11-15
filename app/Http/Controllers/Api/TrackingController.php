<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Order tracking with phone + order number — no account needed. */
class TrackingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:20'],
            'phone' => ['required', 'string', 'max:20'],
        ]);
        $order = Order::query()->where('number', strtoupper(trim($data['number'])))
            ->where('phone', Phone::normalize($data['phone']) ?? '')->first();
        if (! $order) {
            return response()->json(['message' => 'We could not find an order with that number and phone.'], 404);
        }

        return response()->json(['data' => self::present($order)]);
    }

    /** @return array<string, mixed> */
    public static function present(Order $order): array
    {
        $order->loadMissing(['items', 'history']);

        return [
            'number' => $order->number,
            'status' => $order->status,
            'placed_at' => $order->created_at->toIso8601String(),
            'items' => $order->items->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price,
                'made_to_order' => $i->made_to_order, 'making_days' => $i->making_days]),
            'total' => $order->total,
            'amount_due' => $order->isPaid() ? 0 : $order->amountDue(),
            'receipt_under_review' => ! $order->isPaid() && $order->receiptUnderReview(),
            'shipping' => $order->shippingPayable(),
            'city' => $order->city,
            'tracking_number' => $order->tracking_number,
            'timeline' => $order->history->map(fn ($h) => ['status' => $h->to, 'at' => $h->created_at->toIso8601String()]),
        ];
    }
}
