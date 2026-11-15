<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Ledger\Wallet;
use App\Membership\Members;
use App\Models\Commission;
use App\Models\User;
use App\Referral\Attribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Super Admin sees everything (full tree, commissions, wallets). */
class ReferralController extends Controller
{
    public function tree(Request $request, User $user): JsonResponse
    {
        $depth = min(3, max(1, (int) $request->input('depth', 3)));

        return response()->json(['data' => [
            'upline' => $this->upline($user),
            'node' => $this->node($user, $depth),
        ]]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'available', 'reversed', 'skipped'])],
            'type' => ['nullable', Rule::in(['membership', 'signals', 'products'])],
            'recipient_id' => ['nullable', 'integer'], 'level' => ['nullable', 'integer', 'between:1,3'],
            'per_page' => ['nullable', 'integer', 'max:200'],
        ]);
        $page = Commission::query()->with(['recipient:id,first_name,last_name,phone'])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('recipient_id'), fn ($q, $v) => $q->where('recipient_id', $v))
            ->when($request->input('level'), fn ($q, $v) => $q->where('level', $v))
            ->latest('id')->paginate((int) $request->input('per_page', 50));

        return response()->json($page);
    }

    public function changeReferrer(Request $request, User $user, Attribution $attribution): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Only Super Admin can change a referrer.');
        $data = $request->validate([
            'referrer_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $new = $data['referrer_id'] ? User::query()->findOrFail($data['referrer_id']) : null;
        $attribution->changeReferrer($user, $new, $data['reason'], (int) $request->user()->id);

        return response()->json(['data' => ['referrer_id' => $user->fresh()->referrer_id]]);
    }

    /** @return list<array<string, mixed>> */
    private function upline(User $user): array
    {
        $out = [];
        $cur = $user->referrer;
        for ($i = 0; $i < 3 && $cur; $i++) {
            $out[] = ['id' => $cur->id, 'name' => $cur->name, 'code' => $cur->referral_code];
            $cur = $cur->referrer;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function node(User $user, int $depth): array
    {
        $children = $depth > 0 ? User::query()->with('membership')->where('referrer_id', $user->id)->get() : collect();

        return [
            'id' => $user->id, 'name' => $user->name, 'phone' => $user->phone, 'code' => $user->referral_code,
            'active' => Members::isActive($user), 'joined' => $user->created_at?->toDateString(),
            'wallet' => Wallet::for($user)->summary(),
            'children_count' => User::query()->where('referrer_id', $user->id)->count(),
            'children' => $children->map(fn (User $c) => $this->node($c, $depth - 1))->values(),
        ];
    }
}
