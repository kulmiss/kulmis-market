<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Signal;
use App\Models\SignalSubscription;
use App\Signals\SignalAccess;
use App\Signals\SignalPublisher;
use App\Support\Activity;
use App\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SignalController extends Controller
{
    public function __construct(private readonly SignalPublisher $publisher, private readonly SignalAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $page = Signal::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('result'), fn ($q, $v) => $q->where('result', $v))
            ->when($request->input('pair'), fn ($q, $v) => $q->where('pair', 'like', "%$v%"))
            ->latest('id')->paginate(min(200, (int) $request->input('per_page', 25)));

        return response()->json($page);
    }

    /** Create and post to the channel. */
    public function store(Request $request): JsonResponse
    {
        $signal = Signal::query()->create($this->validated($request) + ['status' => 'open', 'created_by' => $request->user()?->id]);
        $this->publisher->post($signal);
        Activity::log('signal.posted', $signal);

        return response()->json(['data' => $signal->fresh()], 201);
    }

    public function update(Request $request, Signal $signal): JsonResponse
    {
        if ($signal->isLocked()) {
            throw ValidationException::withMessages(['signal' => 'Closed signals are locked.']);
        }
        $signal->update($this->validated($request));
        Activity::log('signal.edited', $signal);

        return response()->json(['data' => $signal]);
    }

    /** Close with a result; this locks the signal forever. */
    public function close(Request $request, Signal $signal): JsonResponse
    {
        if ($signal->isLocked()) {
            throw ValidationException::withMessages(['signal' => 'Already closed and locked.']);
        }
        $data = $request->validate([
            'result' => ['required', Rule::in(Signal::RESULTS)],
            'result_value' => ['nullable', 'string', 'max:40'],
            'result_note' => ['nullable', 'string', 'max:500'],
        ]);
        $signal->update($data + ['status' => 'closed', 'closed_at' => now(), 'locked_at' => now()]);
        $this->publisher->postResult($signal);
        Activity::log('signal.closed', $signal, $data);

        return response()->json(['data' => $signal]);
    }

    public function subscribers(Request $request): JsonResponse
    {
        $page = SignalSubscription::query()->with('user:id,first_name,last_name')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('phone', 'like', "%$v%")->orWhere('first_name', 'like', "%$v%")))
            ->latest('id')->paginate(min(200, (int) $request->input('per_page', 25)));
        $page->getCollection()->each(fn ($s) => $s->setAttribute('entitled', $s->isEntitled()));

        return response()->json($page);
    }

    public function addSubscriber(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email'],
            'days' => ['required', 'integer', 'min:1', 'max:3650'], 'reason' => ['required', 'string', 'max:500'],
        ]);
        $sub = $this->access->grantManual($data, (int) $data['days'], (int) $request->user()?->id, $data['reason']);

        return response()->json(['data' => array_merge($sub->toArray(), ['telegram_link' => $sub->deepLink()])], 201);
    }

    public function removeSubscriber(Request $request, SignalSubscription $subscription): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        if ($subscription->source !== SignalSubscription::MEMBERSHIP) {
            $subscription->update(['grace_ends_at' => now()->subSecond(), 'current_period_end' => now()->subSecond()]);
        }
        $this->access->remove($subscription, $data['reason'], $request->user()?->id);

        return response()->json(['data' => $subscription->fresh()]);
    }

    public function telegramStatus(TelegramClient $telegram): JsonResponse
    {
        $info = null;
        $error = null;
        if ($telegram->configured()) {
            try {
                $info = $telegram->call('getWebhookInfo');
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return response()->json(['data' => [
            'configured' => $telegram->configured(),
            'bot_username' => config('services.telegram.bot_username'),
            'channel_id_set' => (bool) config('services.telegram.channel_id'),
            'webhook_secret_set' => (bool) config('services.telegram.webhook_secret'),
            'webhook' => $info ? ['url' => $info['url'] ?? null, 'pending' => $info['pending_update_count'] ?? null, 'last_error' => $info['last_error_message'] ?? null] : null,
            'error' => $error,
        ]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'pair' => ['required', 'string', 'max:40'],
            'direction' => ['required', Rule::in(['long', 'short'])],
            'entry' => ['required', 'string', 'max:80'],
            'targets' => ['required', 'array', 'min:1', 'max:10'], 'targets.*' => ['string', 'max:40'],
            'stop_loss' => ['required', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
