<?php

namespace App\Http\Controllers\Admin;

use App\Badges\ProfitGuard;
use App\Http\Controllers\Controller;
use App\Membership\PaidAccess;
use App\Models\Order;
use App\Support\Features;
use App\Support\OffFeatureTexts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            ->map(fn (array $r) => ['key' => $r['key'], 'label' => $r['label'], 'effective' => $r['effective']])->values(),
            // Words of each feature: editors warn "This text mentions Signals, which is off".
            'words' => config('feature_words')]);
    }

    /** What switching a feature off means right now, in a few lines (shown before you switch). */
    public function impact(string $key, PaidAccess $access): JsonResponse
    {
        abort_unless(Features::exists($key), 404);
        $lines = [];
        $day = fn (?string $iso) => $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->format('j M Y') : '';
        foreach (PaidAccess::FEATURES as $paid) {
            if ($key === $paid) { // members' signals follow their membership; guest buyers are not touched by the membership switch
                $s = $access->summary($paid);
                if ($s['people'] > 0) {
                    $what = $paid === 'membership' ? 'membership' : 'signals (bought without membership)';
                    $lines[] = "{$s['people']} ".($s['people'] === 1 ? 'person has' : 'people have')." $what until their month ends (the last on {$day($s['last_until'])}). Nobody can buy or renew.";
                }
            }
        }
        $count = fn (string $table, callable $where) => (int) $where(DB::table($table))->count();
        $extra = match ($key) {
            'shop' => [$count('orders', fn ($q) => $q->whereIn('status', [Order::PAID, Order::MAKING, Order::SHIPPED])->where('is_demo', false)), 'open orders stay here for you to finish.'],
            'wallet', 'wallet.cashout' => [$count('payout_requests', fn ($q) => $q->whereIn('status', ['requested', 'on_hold'])), 'cash-outs are waiting for you to pay.'],
            'referral' => [$count('commissions', fn ($q) => $q->where('status', 'pending')), 'team earnings are on the way (they still become ready). No new ones.'],
            'site.help_centre' => [$count('contact_messages', fn ($q) => $q->where('status', 'new')), 'help messages are not answered yet.'],
            default => [0, ''],
        };
        if ($extra[0] > 0) {
            $lines[] = $extra[0].' '.$extra[1];
        }
        $lines[] = 'Customers and members will see nothing of it: no pages, links, words or messages. Its admin pages and history stay.';

        return response()->json(['data' => ['key' => $key, 'lines' => $lines, 'paid' => in_array($key, PaidAccess::FEATURES, true) ? $access->summary($key) : null]]);
    }

    /** Texts you edit that still mention a switched-off feature. */
    public function texts(): JsonResponse
    {
        return response()->json(['data' => OffFeatureTexts::all()]);
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
