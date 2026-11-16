<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\MoneyHealth;
use App\Reports\WhatIf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MoneyHealthController extends Controller
{
    public function show(Request $request, MoneyHealth $health): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', 'in:month,last_month,year,all'], 'demo' => ['nullable', 'boolean']]);
        $demo = (bool) ($data['demo'] ?? false);
        [$from, $to] = match ($data['period'] ?? 'month') {
            'last_month' => [now()->startOfMonth()->subMonthNoOverflow(), now()->startOfMonth()->subSecond()],
            'year' => [now()->startOfYear(), now()],
            'all' => [Carbon::create(2000), now()],
            default => [now()->startOfMonth(), now()],
        };

        return response()->json(['data' => $health->summary($from, $to, $demo) + ['months' => $health->months(6, $demo)]]);
    }

    public function whatIf(Request $request, WhatIf $whatIf): JsonResponse
    {
        $data = $request->validate(['members' => ['required', 'integer', 'min:0', 'max:1000000'], 'invites' => ['required', 'numeric', 'min:0', 'max:50']]);

        return response()->json(['data' => $whatIf->forShop((int) $data['members'], (float) $data['invites'])]);
    }
}
