<?php

namespace App\Support;

use App\Demo\Demo;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Append-only audit log. The IP address is stored only for an authenticated
 * actor (staff/members), never for guests.
 */
class Activity
{
    /** @param array<string, mixed> $properties */
    public static function log(string $action, ?Model $subject = null, array $properties = [], ?string $reason = null, ?int $actorId = null): ActivityLog
    {
        $actorId ??= Auth::id();
        $request = app()->runningInConsole() ? null : request();

        return ActivityLog::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'reason' => $reason,
            'ip_address' => $actorId && $request ? $request->ip() : null,
            'is_demo' => Demo::isDemoModel($subject),
        ]);
    }
}
