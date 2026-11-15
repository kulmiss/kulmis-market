<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Signal;
use App\Models\User;
use App\Payments\PaymentService;
use App\Referral\Attribution;
use App\Security\SecurityAlerts;
use App\Signals\SignalAccess;
use App\Signals\TelegramBot;
use App\Support\Features;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SignalController extends Controller
{
    public function __construct(private readonly SignalAccess $access, private readonly PaymentService $payments) {}

    public function info(): JsonResponse
    {
        return response()->json(['data' => [
            'guest_price' => Settings::int('signals.guest_price'),
            'included_in_membership' => Features::enabled('membership'),
            'membership_price' => Settings::int('membership.price'),
            'risk_notice' => Settings::string('signals.risk_notice'),
        ]]);
    }

    /** Public results log (switchable). */
    public function performance(): JsonResponse
    {
        $closed = Signal::query()->where('status', 'closed')->whereIn('result', ['win', 'loss', 'breakeven']);
        $wins = (clone $closed)->where('result', 'win')->count();
        $losses = (clone $closed)->where('result', 'loss')->count();
        $be = (clone $closed)->where('result', 'breakeven')->count();
        $decided = $wins + $losses;

        return response()->json(['data' => [
            'totals' => ['closed' => $wins + $losses + $be, 'wins' => $wins, 'losses' => $losses, 'breakeven' => $be,
                'win_rate' => $decided ? round($wins * 100 / $decided, 1) : null],
            'recent' => Signal::query()->where('status', 'closed')->latest('closed_at')->limit(50)
                ->get(['pair', 'direction', 'result', 'result_value', 'posted_at', 'closed_at']),
            'risk_notice' => Settings::string('signals.risk_notice'),
        ]]);
    }

    /** Guests buy a month (Chapa). */
    public function subscribe(Request $request): JsonResponse
    {
        abort_if(Features::disabled('payments.chapa'), 404);
        $data = $this->buyer($request);
        try {
            $purchase = $this->access->newGuestPurchase($data, $this->user($request));
            app(Attribution::class)->forSignals($request, $purchase->subscription);
            $payment = $this->payments->startChapa($purchase, url('/signals?paid=1'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['signals' => $e->getMessage()]);
        }

        return response()->json(['data' => ['checkout_url' => $payment->checkout_url, 'amount' => $payment->amount]], 201);
    }

    public function proof(Request $request): JsonResponse
    {
        abort_if(Features::disabled('payments.manual'), 404);
        $data = $this->buyer($request) + $request->validate([
            'method' => ['required', Rule::in([Payment::BANK, Payment::TELEBIRR])],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        try {
            $purchase = $this->access->newGuestPurchase($data, $this->user($request));
            app(Attribution::class)->forSignals($request, $purchase->subscription);
            $this->payments->submitProof($purchase, $data['method'], $request->file('proof'), $data['reference'] ?? null, $data['phone']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['signals' => $e->getMessage()]);
        }

        return response()->json(['data' => ['status' => 'awaiting_review']], 201);
    }

    /** Members: get the personal Telegram link (free). */
    public function memberAccess(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->isActiveMember(), 403, 'Signals are included for active members.');
        $sub = $this->access->grantMember($user);

        return response()->json(['data' => ['telegram_link' => $sub->deepLink(), 'joined' => $sub->joined_at !== null]]);
    }

    /** Telegram webhook (secret token header required). */
    public function telegram(Request $request, TelegramBot $bot): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            app(SecurityAlerts::class)->badSignature('Telegram');

            return response()->json(['message' => 'Forbidden'], 403);
        }
        // Telegram retries deliveries: each update_id is handled once.
        $updateId = $request->input('update_id');
        if (is_int($updateId) && ! Cache::add('telegram:update:'.$updateId, 1, now()->addDays(2))) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }
        if (Features::enabled('telegram_bot')) {
            $bot->handle($request->all());
        }

        return response()->json(['ok' => true]);
    }

    /** @return array{first_name:string,last_name?:string,phone:string,email?:?string} */
    private function buyer(Request $request): array
    {
        $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);

        return $request->validate([
            'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['required', fn ($a, $v, $fail) => Phone::isValid($v) ?: $fail('Enter a valid Ethiopian mobile number.')],
            'email' => ['nullable', 'email', 'max:255'],
            'referral_code' => ['nullable', 'string', 'max:16'],
        ]);
    }

    private function user(Request $request): ?User
    {
        $u = auth('sanctum')->user();

        return $u instanceof User ? $u : null;
    }
}
