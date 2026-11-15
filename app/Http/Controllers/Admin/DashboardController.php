<?php

namespace App\Http\Controllers\Admin;

use App\Demo\Demo;
use App\Http\Controllers\Controller;
use App\Reports\Dashboard;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request, Dashboard $dashboard): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'data' => ['nullable', 'in:real,demo']]);
        $to = $request->input('to') ? Carbon::parse($request->input('to'))->endOfDay() : now()->endOfDay();
        $from = $request->input('from') ? Carbon::parse($request->input('from'))->startOfDay() : $to->copy()->subDays(29)->startOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose at most one year.');

        $dataset = Demo::dataset($request->input('data'));

        return response()->json(['data' => $dashboard->build($from, $to, $dataset === 'demo')
            + ['demo' => ['visible' => Demo::visible(), 'exists' => Demo::exists()]]]);
    }

    /** Health page: is cron running, queue backlog, what's off. */
    public function status(): JsonResponse
    {
        $beat = Cache::get('scheduler.heartbeat');

        return response()->json(['data' => [
            'scheduler_last_run' => $beat,
            'scheduler_ok' => $beat && Carbon::parse($beat)->gt(now()->subMinutes(5)),
            'queue_waiting' => DB::table('jobs')->count(),
            'queue_failed' => DB::table('failed_jobs')->count(),
            'features_off' => collect(Features::overview())->where('effective', false)->pluck('label')->values(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'https' => str_starts_with((string) config('app.url'), 'https://'),
            'integrations' => [
                'telegram' => (bool) config('services.telegram.bot_token'),
                'sms' => config('services.sms.driver'),
                'mail' => config('mail.default'),
            ],
        ]]);
    }
}
