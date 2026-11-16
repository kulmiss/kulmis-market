<?php

namespace App\Http\Controllers\Admin;

use App\Fraud\FraudGuard;
use App\Http\Controllers\Controller;
use App\Models\FraudFlag;
use App\Models\User;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FraudController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = FraudFlag::query()->with('user:id,first_name,last_name,phone,referral_code,status')
            ->when($request->input('status', 'open'), fn ($q, $v) => $v === 'all' ? $q : $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('q'), fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('first_name', 'like', "%$v%")->orWhere('last_name', 'like', "%$v%")->orWhere('phone', 'like', "%$v%")))
            ->tap(fn ($q) => TableQuery::sort($q, $request, ['type' => 'type', 'status' => 'status', 'created' => 'created_at']))->paginate(min(200, (int) $request->input('per_page', 50)));

        return response()->json($page);
    }

    public function review(Request $request, FraudFlag $flag, FraudGuard $guard): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['cleared', 'confirmed'])],
            'note' => ['nullable', 'string', 'max:500'],
            'suspend' => ['boolean'],
        ]);
        $guard->review($flag, $data['decision'], (int) $request->user()?->id, $data['note'] ?? null, $request->boolean('suspend'));

        return response()->json(['data' => $flag->fresh()]);
    }

    public function flag(Request $request, User $user, FraudGuard $guard): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $guard->flag($user, 'manual', ['note' => $data['note']])], 201);
    }
}
