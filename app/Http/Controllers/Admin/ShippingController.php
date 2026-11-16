<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\ShippingZone;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingController extends Controller
{
    public function zones(): JsonResponse
    {
        return response()->json(['data' => ShippingZone::query()->orderBy('position')->get()
            ->map(fn (ShippingZone $z) => $z->toArray() + ['courier_warning' => $z->courierWarning()])]);
    }

    public function storeZone(Request $request): JsonResponse
    {
        $zone = ShippingZone::query()->create($this->zoneData($request));
        Activity::log('shipping_zone.created', $zone);

        return response()->json(['data' => $zone], 201);
    }

    public function updateZone(Request $request, ShippingZone $zone): JsonResponse
    {
        $zone->update($this->zoneData($request));
        Activity::log('shipping_zone.updated', $zone);

        return response()->json(['data' => $zone]);
    }

    public function storeCourier(Request $request): JsonResponse
    {
        $courier = Courier::query()->create($this->courierData($request));
        Activity::log('courier.created', $courier);

        return response()->json(['data' => $courier], 201);
    }

    public function updateCourier(Request $request, Courier $courier): JsonResponse
    {
        $courier->update($this->courierData($request));
        Activity::log('courier.updated', $courier);

        return response()->json(['data' => $courier]);
    }

    /** @return array<string, mixed> */
    private function zoneData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'cities' => ['present', 'array', 'max:500'], 'cities.*' => ['string', 'max:80'],
            'base_fee' => ['required', 'integer', 'min:0'], 'included_grams' => ['nullable', 'integer', 'min:0'],
            'per_kg_fee' => ['nullable', 'integer', 'min:0'],
            'courier_cost' => ['nullable', 'integer', 'min:0'], 'courier_per_kg' => ['nullable', 'integer', 'min:0'],
            'eta_days_min' => ['nullable', 'integer', 'min:0'], 'eta_days_max' => ['nullable', 'integer', 'gte:eta_days_min'],
            'is_default' => ['boolean'], 'is_active' => ['boolean'], 'position' => ['nullable', 'integer'],
        ]);
        if (! empty($data['is_default'])) {
            ShippingZone::query()->update(['is_default' => false]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function courierData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30'],
            'tracking_url' => ['nullable', 'url', 'max:255'], 'is_active' => ['boolean'],
        ]);
    }
}
