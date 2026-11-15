<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Ledger\Wallet;
use App\Membership\MembershipService;
use App\Models\MembershipPurchase;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'membership' => ['nullable', Rule::in(['active', 'grace', 'expired', 'none'])],
            'per_page' => ['nullable', 'integer', 'max:200'],
        ]);
        $page = User::query()->where('role', User::ROLE_CUSTOMER)->with('membership')
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('first_name', 'like', "%$v%")
                ->orWhere('last_name', 'like', "%$v%")->orWhere('phone', 'like', "%$v%")->orWhere('email', 'like', "%$v%")))
            ->when($request->input('membership') === 'none', fn ($q) => $q->doesntHave('membership'))
            ->when(in_array($request->input('membership'), ['active', 'grace', 'expired'], true),
                fn ($q) => $q->whereHas('membership', fn ($m) => $m->where('status', $request->input('membership'))))
            ->latest('id')->paginate((int) $request->input('per_page', 25));

        return response()->json($page);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => [
            'user' => $user->load('membership'),
            'purchases' => MembershipPurchase::query()->where('user_id', $user->id)->latest('id')->limit(50)->get(),
            'wallet' => Wallet::for($user)->summary(),
        ]]);
    }

    /** Extend (+days) or end (negative days) a membership by hand, with a reason. */
    public function adjust(Request $request, User $user, MembershipService $service): JsonResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'not_in:0', 'between:-3650,3650'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => $service->adjust($user, (int) $data['days'], $data['reason'], (int) $request->user()?->id)]);
    }

    public function status(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])], 'reason' => ['required', 'string', 'max:500']]);
        $user->forceFill(['status' => $data['status']])->save();
        if ($data['status'] === 'suspended') {
            $user->tokens()->delete();
        }
        Activity::log('customer.'.$data['status'], $user, [], $data['reason']);

        return response()->json(['data' => $user]);
    }
}
