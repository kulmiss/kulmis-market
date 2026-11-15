<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Orders\OrderService;
use App\Payments\PaymentService;
use App\Payments\Receipt;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'max:20'], 'provider' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:80'], 'per_page' => ['nullable', 'integer', 'max:200'],
        ]);
        $page = Payment::query()->with(['payable', 'refunds'])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('provider'), fn ($q, $v) => $q->where('provider', $v))
            ->when($request->input('order_id'), fn ($q, $v) => $q->where('payable_type', (new Order)->getMorphClass())->where('payable_id', $v))
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('tx_ref', 'like', "%$v%")
                ->orWhere('provider_reference', 'like', "%$v%")->orWhere('proof_reference', 'like', "%$v%")))
            ->latest('id')->paginate((int) $request->input('per_page', 25));

        return response()->json($page);
    }

    public function show(Payment $payment): JsonResponse
    {
        return response()->json(['data' => $payment->load(['payable', 'refunds'])->makeVisible('meta')]);
    }

    /** Sent as an inert file: no scripts, no sniffing, never cached by proxies. */
    public function proof(Payment $payment): StreamedResponse
    {
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);
        $pdf = str_ends_with($payment->proof_path, '.pdf');
        $type = $pdf ? 'application/pdf' : (str_ends_with($payment->proof_path, '.jpg') ? 'image/jpeg' : 'application/octet-stream');
        Activity::log('payment.proof_viewed', $payment);

        return Storage::disk('local')->response($payment->proof_path, 'proof-'.$payment->id.($pdf ? '.pdf' : '.jpg'), [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function approve(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->payments->approveProof($payment, (int) $request->user()?->id, $data['note'] ?? null));
    }

    public function reject(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->payments->rejectProof($payment, (int) $request->user()?->id, $data['reason']));
    }

    /** Ask Chapa again (for "customer says they paid"). */
    public function check(Payment $payment): JsonResponse
    {
        abort_unless($payment->provider === Payment::CHAPA, 422);

        return response()->json(['data' => $this->payments->confirmChapa($payment->tx_ref)]);
    }

    public function refund(Request $request, Payment $payment, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'via_provider' => ['boolean'],
            'restock' => ['boolean'],
        ]);

        return $this->attempt(function () use ($payment, $data, $request, $orders) {
            $this->payments->refund($payment, (int) $data['amount'], $data['reason'], $request->user()?->id, $request->boolean('via_provider', true));
            if ($request->boolean('restock') && $payment->payable instanceof Order) {
                $orders->restock($payment->payable->load('items'));
            }

            return $payment->fresh(['refunds']);
        });
    }

    public function receipt(Payment $payment, Receipt $receipt): Response
    {
        return $receipt->download($payment);
    }

    private function attempt(callable $fn): JsonResponse
    {
        try {
            return response()->json(['data' => $fn()]);
        } catch (InvalidArgumentException|\RuntimeException $e) {
            throw ValidationException::withMessages(['payment' => $e->getMessage()]);
        }
    }
}
