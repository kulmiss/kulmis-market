<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Referral\ReferralCodes;
use App\Referral\TeamView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function show(Request $request, TeamView $view): JsonResponse
    {
        return response()->json(['data' => $view->for($request->user())]);
    }

    public function history(Request $request, TeamView $view): JsonResponse
    {
        return response()->json(['data' => $view->history($request->user())]);
    }

    public function changeCode(Request $request, ReferralCodes $codes): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $codes->change($request->user(), $data['code']);

        return response()->json(['data' => ['code' => $request->user()->referral_code]]);
    }
}
