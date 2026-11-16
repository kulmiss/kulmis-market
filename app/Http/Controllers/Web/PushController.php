<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserNotification;
use App\Notify\Inbox;
use App\Notify\NotifyPrefs;
use App\Notify\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** "Phone notifications": say yes on a phone, choose what for, say no again. */
class PushController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless(WebPush::configured(), 404);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
            'device' => ['nullable', 'string', 'max:80'],
            'kinds' => ['nullable', 'array'], 'kinds.*' => [Rule::in(Inbox::PUSH)],
        ]);
        if (! WebPush::allowedEndpoint($data['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => 'This browser\'s notification service is not supported.']);
        }
        /** @var User $user */
        $user = $request->user();
        PushSubscription::query()->updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])],
            ['user_id' => $user->id, 'endpoint' => $data['endpoint'], 'device' => strip_tags((string) ($data['device'] ?? '')) ?: null, 'failures' => 0]);
        // At most 10 phones/browsers per person: the oldest goes.
        $keep = PushSubscription::query()->where('user_id', $user->id)->latest('updated_at')->limit(10)->pluck('id');
        PushSubscription::query()->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();

        $kinds = $data['kinds'] ?? ['earning', 'order', 'bonus', 'cashout'];
        NotifyPrefs::set($user, array_combine(array_map(fn ($k) => "push.$k", Inbox::PUSH), array_map(fn ($k) => in_array($k, $kinds, true), Inbox::PUSH)));

        return response()->json(['data' => ['message' => 'Phone notifications are on.']], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['nullable', 'string', 'max:1000']]);
        $q = PushSubscription::query()->where('user_id', $request->user()->id);
        // No address = "turn off on all my phones".
        isset($data['endpoint']) ? $q->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete() : $q->delete();

        return response()->json(['data' => ['message' => 'Phone notifications are off.']]);
    }

    /** Asked by the phone when a push arrives: only safe words for the lock screen. */
    public function latest(Request $request): JsonResponse
    {
        $n = UserNotification::query()->shown()->where('user_id', $request->user()->id)->whereNull('read_at')->latest('id')->first();

        return response()->json(['data' => $n ? ['title' => Inbox::lockScreenTitle($n->type), 'body' => 'Tap to open.', 'url' => '/account/notifications']
            : ['title' => 'You have news', 'body' => 'Tap to open.', 'url' => '/account/notifications']])->header('Cache-Control', 'no-store, private');
    }
}
