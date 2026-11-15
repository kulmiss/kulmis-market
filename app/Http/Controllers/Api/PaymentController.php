<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\PaymentService;
use App\Payments\Receipt;
use App\Support\Features;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** Payment options for a pending order (number + phone, like tracking). */
    public function options(Request $request, string $number): JsonResponse
    {
        $order = $this->order($request, $number);

        return response()->json(['data' => [
            'amount_due' => $order->isSettled() ? 0 : $order->amountDue(),
            'methods' => self::methods(),
        ]]);
    }

    public function proof(Request $request, string $number): JsonResponse
    {
        abort_if(Features::disabled('payments.manual'), 404);
        $data = $request->validate([
            'method' => ['required', Rule::in([Payment::BANK, Payment::TELEBIRR])],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'payer_account' => ['nullable', 'string', 'max:120'],
        ]);
        $order = $this->order($request, $number);
        try {
            $payment = $this->payments->submitProof($order, $data['method'], $request->file('proof'), $data['reference'] ?? null, $data['payer_account'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return response()->json(['data' => ['status' => $payment->status, 'message' => 'Thank you! We will confirm your payment shortly.']], 201);
    }

    public function receipt(string $payment, Receipt $receipt): Response
    {
        return $receipt->download(Payment::query()->where('uuid', $payment)->firstOrFail());
    }

    /** @return list<array<string, mixed>> */
    public static function methods(): array
    {
        $methods = [];
        if (Features::enabled('payments.manual')) {
            $methods[] = ['id' => Payment::TELEBIRR, 'label' => 'Telebirr transfer + screenshot', 'instructions' => Settings::string('payments.telebirr_details')];
            $methods[] = ['id' => Payment::BANK, 'label' => 'Bank transfer + receipt photo', 'instructions' => Settings::string('payments.bank_details')];
        }

        return $methods;
    }

    private function order(Request $request, string $number): Order
    {
        $phone = Phone::normalize((string) $request->input('phone'));
        $order = Order::query()->where('number', strtoupper($number))->where('phone', $phone ?? '')->first();
        abort_if($order === null, 404, 'Order not found.');

        return $order;
    }
}
