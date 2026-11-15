<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** "Sign out all devices": website sessions, app tokens and remember-me. */
class Sessions
{
    public function __construct(private readonly SecurityAlerts $alerts) {}

    /** @return int how many sessions/tokens were ended */
    public function signOutOthers(User $user, ?string $keepSessionId = null, ?int $keepTokenId = null, bool $alert = true): int
    {
        $sessions = 0;
        if (config('session.driver') === 'privacy-database' || config('session.driver') === 'database') {
            $sessions = DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)
                ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))->delete();
        }
        $tokens = $user->tokens()->when($keepTokenId, fn ($q) => $q->whereKeyNot($keepTokenId))->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        if ($alert) {
            $this->alerts->notify($user, 'signed_out_everywhere');
        }

        return $sessions + $tokens;
    }

    /** @return list<array{id: string, label: string, last_active: string, current: bool}> */
    public function list(User $user, ?string $currentId): array
    {
        if (! in_array(config('session.driver'), ['privacy-database', 'database'], true)) {
            return [];
        }

        return DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->orderByDesc('last_activity')
            ->get(['id', 'user_agent', 'last_activity'])
            ->map(fn ($s) => ['id' => substr(hash('sha256', (string) $s->id), 0, 12), 'label' => Devices::label((string) $s->user_agent),
                'last_active' => date(DATE_ATOM, (int) $s->last_activity), 'current' => $s->id === $currentId])->values()->all();
    }
}
