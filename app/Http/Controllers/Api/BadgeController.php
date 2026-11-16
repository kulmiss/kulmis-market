<?php

namespace App\Http\Controllers\Api;

use App\Badges\BadgeEngine;
use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Notify\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Member: badges with progress, celebration "seen", and in-app notifications. */
class BadgeController extends Controller
{
    public function __construct(private readonly BadgeEngine $engine) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->engine->progress($user), 'summary' => $this->engine->summary($user)]);
    }

    public function seen(Request $request): JsonResponse
    {
        $this->engine->markSeen($request->user());

        return response()->json(['ok' => true]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $q = UserNotification::query()->shown()->where('user_id', $request->user()->id);

        return response()->json([
            'data' => (clone $q)->latest('id')->limit(30)->get(['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at'])
                ->each(fn (UserNotification $n) => $n->url = Inbox::link($n->url)), // no links to switched-off pages
            'unread' => (clone $q)->whereNull('read_at')->count(),
        ]);
    }

    public function readNotifications(Request $request): JsonResponse
    {
        UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
