<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FeatureController extends Controller
{
    /** The "what's on/off" overview. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Features::overview()]);
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
        foreach ($data['features'] as $key => $enabled) {
            Features::set((string) $key, $enabled, $request->user()?->id);
        }

        return $this->index();
    }
}
