<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = Coupon::query()
            ->when($request->input('q'), fn ($q, $v) => $q->where('code', 'like', '%'.strtoupper((string) $v).'%'))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->latest('id')->paginate(min(200, (int) $request->input('per_page', 50)));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $coupon = Coupon::query()->create($this->validated($request));
        Activity::log('coupon.created', $coupon);

        return response()->json(['data' => $coupon], 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $coupon->update($this->validated($request, $coupon));
        Activity::log('coupon.updated', $coupon);

        return response()->json(['data' => $coupon]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'type' => ['required', Rule::in([Coupon::PERCENT, Coupon::FIXED])],
            'value' => ['required', 'integer', 'min:1', Rule::when($request->input('type') === Coupon::PERCENT, ['max:10000'])],
            'min_subtotal' => ['nullable', 'integer', 'min:0'],
            'max_discount' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:1'], 'max_uses_per_customer' => ['nullable', 'integer', 'min:1'],
            'members_only' => ['boolean'], 'is_active' => ['boolean'], 'note' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
