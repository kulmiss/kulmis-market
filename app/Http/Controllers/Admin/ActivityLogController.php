<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'actor_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:120'],
            'subject_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = ActivityLog::query()
            ->with('actor:id,first_name,last_name,role')
            ->when($request->input('action'), fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($request->input('actor_id'), fn ($q, $v) => $q->where('actor_id', $v))
            ->when($request->input('subject_type'), fn ($q, $v) => $q->where('subject_type', $v))
            ->when($request->input('subject_id'), fn ($q, $v) => $q->where('subject_id', $v))
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('action', 'like', "%$v%")->orWhere('reason', 'like', "%$v%")))
            ->tap(fn ($q) => TableQuery::sort($q, $request, ['action' => 'action', 'when' => 'created_at']))
            ->paginate((int) $request->input('per_page', 50));

        return response()->json($page);
    }

    /** Staff overview: who did how much, and what, in the period. */
    public function staff(Request $request): JsonResponse
    {
        $days = max(1, min(365, (int) $request->input('days', 30)));
        $from = now()->subDays($days);
        $rows = DB::table('activity_log')->join('users', 'users.id', '=', 'activity_log.actor_id')
            ->where('users.role', '!=', 'customer')->where('activity_log.created_at', '>=', $from)
            ->groupBy('activity_log.actor_id', 'users.first_name', 'users.last_name', 'users.role')
            ->selectRaw('activity_log.actor_id as id, users.first_name, users.last_name, users.role, COUNT(*) as actions, MAX(activity_log.created_at) as last_at')
            ->orderByDesc('actions')->get();
        $top = DB::table('activity_log')->whereIn('actor_id', $rows->pluck('id'))->where('created_at', '>=', $from)
            ->groupBy('actor_id', 'action')->selectRaw('actor_id, action, COUNT(*) as n')->orderByDesc('n')->get()->groupBy('actor_id');
        $staff = User::query()->where('role', '!=', 'customer')->get(['id', 'first_name', 'last_name', 'role'])->keyBy('id');

        return response()->json(['data' => ['days' => $days, 'staff' => $staff->map(fn ($u) => [
            'id' => $u->id, 'name' => trim($u->first_name.' '.$u->last_name), 'role' => config("permissions.roles.{$u->role}.label") ?? $u->role,
            'actions' => (int) ($rows->firstWhere('id', $u->id)->actions ?? 0), 'last_at' => $rows->firstWhere('id', $u->id)->last_at ?? null,
            'top' => ($top->get($u->id) ?? collect())->take(5)->map(fn ($t) => ['action' => $t->action, 'n' => (int) $t->n])->values(),
        ])->sortByDesc('actions')->values()]]);
    }
}
