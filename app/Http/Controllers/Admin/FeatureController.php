<?php

namespace App\Http\Controllers\Admin;

use App\Badges\ProfitGuard;
use App\Http\Controllers\Controller;
use App\Membership\PaidAccess;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeatureController extends Controller
{
    /** The "what's on/off" overview. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Features::overview()]);
    }

    /** On/off only, for every staff member: the admin hides screens of switched-off features. */
    public function state(): JsonResponse
    {
        return response()->json(['data' => collect(Features::overview())
            ->map(fn (array $r) => ['key' => $r['key'], 'effective' => $r['effective']])->values()]);
    }

    /** Who already paid for a feature (membership, signals) and keeps it until their month ends. */
    public function paidAccess(string $feature, PaidAccess $access): JsonResponse
    {
        abort_unless(in_array($feature, PaidAccess::FEATURES, true), 404);

        return response()->json(['data' => $access->summary($feature) + ['rows' => array_slice($access->people($feature), 0, 200)]]);
    }

    /** "End access now", optionally adding the unused days to their wallet. Only while the feature is off. */
    public function endAccess(Request $request, string $feature, PaidAccess $access): JsonResponse
    {
        abort_unless(in_array($feature, PaidAccess::FEATURES, true), 404);
        $data = $request->validate(['credit' => ['required', 'boolean']]);
        try {
            $stats = $access->endNow($feature, (bool) $data['credit'], (int) $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['feature' => $e->getMessage()]);
        }

        return response()->json(['data' => $stats]);
    }

    /** Bulk update: {"features": {"signals": false, "referral.level3": true}} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['features' => ['required', 'array', 'min:1']]);
        foreach ($data['features'] as $key => $enabled) {
            if (! Features::exists((string) $key) || ! is_bool($enabled)) {
                throw ValidationException::withMessages(["features.$key" => 'Unknown feature or not true/false.']);
            }
        }
        try {
            DB::transaction(function () use ($data, $request) {
                foreach ($data['features'] as $key => $enabled) {
                    Features::set((string) $key, $enabled, $request->user()?->id);
                }
                // Turning on a payout must still leave you at least 65% of every membership.
                if (array_filter($data['features'], fn ($on, $k) => $on && in_array((string) $k, ProfitGuard::PAYOUT_SWITCHES, true), ARRAY_FILTER_USE_BOTH)) {
                    ProfitGuard::assertSafe('features');
                }
            });
        } finally {
            Features::flush(); // a rolled-back switch must not stay cached
        }

        return $this->index();
    }
}
