<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Referral\Engagement;
use App\Referral\ReferralCodes;
use App\Referral\TeamView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

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

    public function qr(Request $request, Engagement $engagement): Response
    {
        app(ReferralCodes::class)->assign($request->user());

        return response($engagement->qrSvg(url('/r/'.$request->user()->referral_code)), 200, [
            'Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function top(Request $request, Engagement $engagement): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $request->input('month') ? Carbon::createFromFormat('Y-m', $request->input('month'))->startOfMonth() : now();

        return response()->json(['data' => $engagement->topReferrers($month), 'month' => $month->format('Y-m')]);
    }

    public function changeCode(Request $request, ReferralCodes $codes): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $codes->change($request->user(), $data['code']);

        return response()->json(['data' => ['code' => $request->user()->referral_code]]);
    }
}
