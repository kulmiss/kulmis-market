<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ->latest('id')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json($page);
    }
}
