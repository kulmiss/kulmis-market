<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\ChapaGateway;
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

    public function pay(Request $request, string $number): JsonResponse
    {
        $request->validate(['method' => ['required', Rule::in(['chapa'])]]);
        abort_if(Features::disabled('payments.chapa'), 404);
        $order = $this->order($request, $number);
        try {
            $payment = $this->payments->startChapa($order, url('/track?number='.$order->number.'&paid=1'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return response()->json(['data' => ['checkout_url' => $payment->checkout_url, 'tx_ref' => $payment->tx_ref]], 201);
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

    /** Chapa redirects the customer here (GET ?trx_ref=...). We verify server-side. */
    public function chapaCallback(Request $request): JsonResponse
    {
        $ref = (string) ($request->query('trx_ref') ?? $request->query('tx_ref') ?? '');
        $payment = $ref !== '' ? $this->payments->confirmChapa($ref) : null;

        return response()->json(['status' => $payment->status ?? 'unknown']);
    }

    /** Chapa webhook: signature first, then re-verify with Chapa's API. */
    public function chapaWebhook(Request $request, ChapaGateway $chapa): JsonResponse
    {
        $raw = (string) $request->getContent();
        if (! $chapa->validSignature($raw, $request->header('x-chapa-signature'), $request->header('chapa-signature'))) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }
        $ref = (string) ($request->input('tx_ref') ?? $request->input('trx_ref') ?? '');
        if ($ref !== '') {
            $this->payments->confirmChapa($ref);
        }

        return response()->json(['ok' => true]);
    }

    public function receipt(string $payment, Receipt $receipt): Response
    {
        return $receipt->download(Payment::query()->where('uuid', $payment)->firstOrFail());
    }

    /** @return list<array<string, mixed>> */
    public static function methods(): array
    {
        $methods = [];
        if (Features::enabled('payments.chapa')) {
            $methods[] = ['id' => 'chapa', 'label' => 'Telebirr, CBE Birr, M-Pesa or card (Chapa)', 'instant' => true];
        }
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
